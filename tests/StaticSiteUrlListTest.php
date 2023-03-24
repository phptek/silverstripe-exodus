<?php

namespace PhpTek\Exodus\Test;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use PhpTek\Exodus\Model\StaticSiteContentSourceImportSchema;
use PhpTek\Exodus\Model\StaticSiteContentSourceImportRule;
use PhpTek\Exodus\Model\StaticSiteContentSource;
use PhpTek\Exodus\Processor\StaticSiteURLProcessorDropExtensions;
use PhpTek\Exodus\Tool\StaticSiteUrlList;
use PhpTek\Exodus\Crawl\StaticSiteCrawler;
use PhpTek\Exodus\Processor\StaticSiteMOSSURLProcessor;
use PHPCrawl\PHPCrawlerDocumentInfo;
use SilverStripe\Core\Config\Config;
use PhpTek\Exodus\Tool\StaticSiteUtils;

/**
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteUrlListTest extends SapphireTest
{
    /*
     * @var string
     */
    public static $fixture_file = 'StaticSiteContentSource.yml';

    /**
     * @var boolean
     */
    protected $usesDatabase = true;

    /**
     * @var array
     */
    protected static $extra_dataobjects = [
        StaticSiteContentSourceImportSchema::class,
        StaticSiteContentSourceImportRule::class,
        StaticSiteContentSource::class,
        File::class,
        Image::class,
    ];

    /**
     * @var array
     * Array of URL tests designed for exercising the StaticSiteURLProcessorDropExtensions URL Processor
     */
    public static $url_patterns_for_drop_extensions = [
        '/test/contains-double-slash-normal-and-encoded/%2ftest' => '/test/contains-double-slash-normal-and-encoded/test',
        '/test/contains-double-slash-encoded-and-normal%2f/test' => '/test/contains-double-slash-encoded-and-normal/test',
        '/test/contains-double-slash-encoded%2f%2ftest' => '/test/contains-double-slash-encoded/test',
        '/test/contains-single-slash-normal/test' => '/test/contains-single-slash-normal/test',
        '/test/contains-single-slash-encoded%2ftest' => '/test/contains-single-slash-encoded/test',
        //'/test/contains-slash-encoded-bracket/%28/test' => '/test/contains-slash-encoded-bracket/test',
        //'/test/contains-slash-non-encoded-bracket/(/test' => '/test/contains-slash-non-encoded-bracket/test',
        '/test/contains-UPPER-AND-lowercase/test' => '/test/contains-UPPER-AND-lowercase/test',
        '/test/contains%20single%20encoded%20spaces/test' => '/test/contains%20single%20encoded%20spaces/test',
        '/test/contains%20%20doubleencoded%20%20spaces/test' => '/test/contains%20%20doubleencoded%20%20spaces/test',
        '/test/contains%20single%20encoded%20spaces and non encoded spaces/test' => '/test/contains%20single%20encoded%20spaces and non encoded spaces/test'
    ];

    /**
     * @var array
     * Array of URL tests designed for exercising the StaticSiteMOSSURLProcessor URL Processor
     * @todo put these in the fixture file
     */
    public static $url_patterns_for_moss = [
        '/test/Pages/contains-MOSS-style-structure/test' => '/test/contains-MOSS-style-structure/test'
    ];

    /**
     * @var array
     */
    public static $server_codes_bad = [400,404,500,403,301,302];

    /**
     * @var array
     */
    public static $server_codes_good = [200];

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = ASSETS_PATH . '/staticsiteconnector/tests/static-site-1/';

        // Cache dirs
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        Config::inst()->nest();
        StaticSiteUtils::config()->set('log_file', null);
    }

    /**
     * Run once for the whole suite of StaticSiteFileTransformerTest tests
     *
     * @return void
     */
    public function tearDownOnce(): void
    {
        // Clear all images that have been saved during this test-run
        $this->delTree(ASSETS_PATH . '/test-graphics');

        parent::tearDownOnce();
    }

    /**
     *
     * @param type $dir
     * @return type
     */
    private function delTree($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }

    /**
     * Tests various facets of our URL list cache
     */
    public function testInstantiateStaticSiteUrlList()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsHTML7');
        $urlList = StaticSiteUrlList::create($source, $this->cacheDir);

        $this->assertGreaterThan(1, strlen($urlList->getProperty('baseURL')));
        $this->assertGreaterThan(1, strlen($urlList->getProperty('cacheDir')));
        $this->isInstanceOf('StaticSiteContentSource', $urlList->getProperty('source'));
    }

    /**
     * @todo What's this? "Simplifying" https into http??
     */
    public function testSimplifyUrl()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsHTML7');
        $urlList = StaticSiteUrlList::create($source, $this->cacheDir);

        $this->assertEquals('http://www.stuff.co.nz', $urlList->simplifyUrl('http://stuff.co.nz'));
        $this->assertEquals('http://www.stuff.co.nz', $urlList->simplifyUrl('https://stuff.co.nz'));
        $this->assertEquals('http://www.stuff.co.nz', $urlList->simplifyUrl('http://www.stuff.co.nz'));
        $this->assertEquals('http://www.stuff.co.nz', $urlList->simplifyUrl('https://www.stuff.co.nz'));
        $this->assertEquals('http://www.STUFF.co.nz', $urlList->simplifyUrl('http://STUFF.co.nz'));
    }

    /*
     * Perhaps the most key method in the whole class: handleDocumentInfo() extends the default functionality of
     * PHPCrawler and decides what gets parsed and what doesn't, according to the file info returned by the host webserver.
     * handleDocumentInfo() then calls StaticSiteUrlList#saveURLs(), addURL(), addAbsoluteURL() etc which all have
     * a URL processing function.
     */

    /**
     * Tests dodgy URLs with "Bad" server code(s) using the StaticSiteURLProcessorDropExtensions URL Processor
     */
    public function testHandleDocumentInfoBadServerCode_DropExtensions()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsHTML7');
        $urlList = $source->urlList();
        $urlList->setUrlProcessor(StaticSiteURLProcessorDropExtensions::create());
        $crawler = StaticSiteCrawler::create($urlList);

        foreach (array_keys(self::$url_patterns_for_drop_extensions) as $urlFromServer) {
            $urlFromServer = 'http://localhost' . $urlFromServer;

            foreach (self::$server_codes_bad as $code) {
                // Fake a server response into a PHPCrawlerDocumentInfo object
                $crawlerInfo = new PHPCrawlerDocumentInfo();
                $crawlerInfo->url = $urlFromServer;
                $crawlerInfo->http_status_code = $code;
                // If we get a bad server error code, we return non-zero regardless
                $this->assertEquals(1, $crawler->handleDocumentInfo($crawlerInfo));
            }
        }
    }

    /**
     * Tests dodgy URLs with "Bad" server code(s) using the StaticSiteMOSSURLProcessor URL Processor
     */
    public function testHandleDocumentInfoBadServerCode_MOSS()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsHTML7');
        $urlList = StaticSiteUrlList::create($source, $this->cacheDir);
        $urlList->setUrlProcessor(StaticSiteMOSSURLProcessor::create());
        $crawler = StaticSiteCrawler::create($urlList);
        $mossUrltests = array_merge(
            self::$url_patterns_for_drop_extensions,
            self::$url_patterns_for_moss
        );

        foreach (array_keys($mossUrltests) as $urlFromServer) {
            $urlFromServer = 'http://localhost' . $urlFromServer;

            foreach (self::$server_codes_bad as $code) {
                // Fake a server response into a PHPCrawlerDocumentInfo object
                $crawlerInfo = new PHPCrawlerDocumentInfo();
                $crawlerInfo->url = $urlFromServer;
                $crawlerInfo->http_status_code = $code;
                // If we get a bad server error code, we return non-zero regardless
                $this->assertEquals(1, $crawler->handleDocumentInfo($crawlerInfo));
            }
        }
    }
}
