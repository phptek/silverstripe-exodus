<?php

namespace PhpTek\Exodus\Test;

use SilverStripe\Dev\SapphireTest;
use PhpTek\Exodus\Tool\StaticSiteContentExtractor;
use SilverStripe\Core\Config\Config;
use PhpTek\Exodus\Tool\StaticSiteUtils;

/**
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteContentExtractorTest extends SapphireTest
{
    public function setUp(): void
    {
        parent::setUp();

        Config::inst()->nest();
        StaticSiteUtils::config()->set('log_file', null);
    }

    /**
     * Check that we're extra clever by asserting that missing <html> tags
     * are magically prepended.
     */
    public function testPrepareContentNoRootTag()
    {
        $badContent = '<head></head><body><p>test.</p></body>';
        $url = '/test/test.html';
        $mime = 'text/html';
        $extractor = StaticSiteContentExtractor::create($url, $mime, $badContent);
        $extractor->prepareContent();
        $content = $extractor->getContent();

        $this->assertStringContainsString('<html', $content);
        $this->assertEquals(1, count(preg_split('#<html#', $content, -1, PREG_SPLIT_NO_EMPTY)));
    }

    /**
     * Check that we're still clever by asserting that <html> tags which *are*
     * present, so still exist, but do not number more than 1.
     */
    public function testPrepareContentRootTag()
    {
        $goodContent = '<html><head></head><body><p>test.</p></body></html>';
        $url = '/test/test.html';
        $mime = 'text/html';
        $extractor = StaticSiteContentExtractor::create($url, $mime, $goodContent);
        $extractor->prepareContent();
        $content = $extractor->getContent();

        $this->assertStringContainsString('<html', $content);
        $this->assertEquals(1, count(preg_split('#<html#', $content, -1, PREG_SPLIT_NO_EMPTY)));
    }
}
