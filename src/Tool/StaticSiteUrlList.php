<?php

namespace PhpTek\Exodus\Tool;

use PHPCrawl\Enums\PHPCrawlerUrlCacheTypes;
use PhpTek\Exodus\Model\StaticSiteContentSource;
use PhpTek\Exodus\Tool\StaticSiteUtils;
use PhpTek\Exodus\Tool\StaticSiteMimeProcessor;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Represents a set of URLs parsed from a site.
 *
 * Makes use of PHPCrawl to prepare a list of URLs on the site
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */

class StaticSiteUrlList
{
    use Injectable;
    use Configurable;

    /**
     * @var string
     */
    public const CRAWL_STATUS_COMPLETE = 'Complete';

    /**
     * @var string
     */
    public const CRAWL_STATUS_PARTIAL = 'Partial';

    /**
     * @var string
     */
    public const CRAWL_STATUS_NOTSTARTED = 'Not started';

    /**
     *
     * @var string
     */
    private static $undefined_mime_type = 'unknown';

    /**
     *
     * @var string
     */
    protected $baseURL;

    /**
     *
     * @var string
     */
    protected $cacheDir;

    /**
     * Two element array: contains keys 'inferred' and 'regular':
     *  - 'regular' is an array mapping raw URLs to processed URLs
     *  - 'inferred' is an array of inferred URLs
     *
     * @var array
     */
    protected $urls = null;

    /**
     *
     * @var boolean
     */
    protected $autoCrawl = false;

    /**
     *
     * @var StaticSiteUrlProcessor
     */
    protected $urlProcessor = null;

    /**
     *
     * @var array
     */
    protected $extraCrawlURLs = null;

    /**
     * A list of regular expression patterns to exclude from scraping
     *
     * @var array
     */
    protected $excludePatterns = [];

    /**
     * The StaticSiteContentSource object
     *
     * @var StaticSiteContentSource
     */
    protected $source;

    /**
     * Create a new URL List
     * @param StaticSiteContentSource $source
     * @param string $cacheDir The local path to cache data into
     * @return void
     */
    public function __construct(StaticSiteContentSource $source, $cacheDir)
    {
        // baseURL must not have a trailing slash
        $baseURL = $source->BaseUrl;

        if (substr($baseURL, -1) == "/") {
            $baseURL = substr($baseURL, 0, -1);
        }

        // cacheDir must have a trailing slash
        if (substr($cacheDir, -1) != "/") {
            $cacheDir .= "/";
        }

        $this->baseURL = $baseURL;
        $this->cacheDir = $cacheDir;
        $this->source = $source;
    }

    /**
     * Set a URL processor for this URL List.
     *
     * URL processors process the URLs before the site hierarchy and any inferred metadata are generated.
     * These can be used to tranform URLs from CMS's that don't provide a natural hierarchy, into something
     * more useful.
     *
     * @see {@link StaticSiteMOSSURLProcessor} for an example.
     * @param StaticSiteUrlProcessor $urlProcessor
     * @return void
     */
    public function setUrlProcessor(StaticSiteUrlProcessor $urlProcessor = null)
    {
        $this->urlProcessor = $urlProcessor;
    }

    /**
     * Define additional crawl URLs as an array
     * Each of these URLs will be crawled in addition the base URL.
     * This can be helpful if pages are getting missed by the crawl
     *
     * @param array $extraCrawlURLs
     * @return void
     */
    public function setExtraCrawlURls($extraCrawlURLs)
    {
        $this->extraCrawlURLs = $extraCrawlURLs;
    }

    /**
     * Return the additional crawl URLs as an array
     *
     * @return array
     */
    public function getExtraCrawlURLs()
    {
        return $this->extraCrawlURLs;
    }

    /**
     * Set an array of regular expression patterns that should be excluded from
     * being added to the url list.
     *
     * @param array $excludePatterns
     * @return void
     */
    public function setExcludePatterns(array $excludePatterns)
    {
        $this->excludePatterns = $excludePatterns;
    }

    /**
     * Get an array of regular expression patterns that should not be added to
     * the url list.
     *
     * @return array
     */
    public function getExcludePatterns()
    {
        return $this->excludePatterns;
    }

    /**
     * Set whether the crawl should be triggered on demand.
     *
     * @param boolean $autoCrawl
     * @return void
     */
    public function setAutoCrawl($autoCrawl)
    {
        $this->autoCrawl = $autoCrawl;
    }

    /**
     * Returns the status of the spidering.
     *
     * @return string
     */
    public function getSpiderStatus(): string
    {
        if (file_exists($this->cacheDir . 'urls')) {
            if (file_exists($this->cacheDir . 'crawlerid')) {
                return self::CRAWL_STATUS_PARTIAL;
            }

            return self::CRAWL_STATUS_COMPLETE;
        }

        return self::CRAWL_STATUS_NOTSTARTED;
    }

    /**
     * Raw URL+Mime data accessor method, used internally by logic outside of the class.
     *
     * @return mixed string $urls | null if no cached URL/Mime data found
     */
    public function getRawCacheData()
    {
        if ($this->urls) {
            // Don't rely on loadUrls() as it chokes on partially completed imports
            $urls = $this->urls;
        } elseif (file_exists($this->cacheDir . 'urls')) {
            $urls = unserialize(file_get_contents($this->cacheDir . 'urls'));
        } else {
            return null;
        }
        return $urls;
    }

    /**
     * Return the number of URLs crawled so far. If the urlcache is incomplete or
     * doesn't exist, assumes zero.
     *
     * @return mixed integer
     */
    public function getNumURIs(): int
    {
        if (!$urls = $this->getRawCacheData()) {
            return 0;
        }

        if (!isset($urls['regular']) || !isset($urls['regular'])) {
            return 0;
        }

        $_regular = [];
        $_inferred = [];

        foreach ($urls['regular'] as $key => $urlData) {
            array_push($_regular, $urlData['url']);
        }

        foreach ($urls['inferred'] as $key => $urlData) {
            array_push($_inferred, $urlData['url']);
        }

        return count(array_unique($_regular)) + count($_inferred);
    }

    /**
     * Return a map of URLs crawled, with raw URLs as keys and processed URLs as values
     *
     * @return array
     */
    public function getProcessedURLs()
    {
        if ($this->hasCrawled() || $this->autoCrawl) {
            if ($this->urls === null) {
                $this->loadUrls();
            }

            $_regular = [];
            $_inferred = null;

            foreach ($this->urls['regular'] as $key => $urlData) {
                $_regular[$key] = $urlData['url'];
            }
            if ($this->urls['inferred']) {
                $_inferred = [];
                foreach ($this->urls['inferred'] as $key => $urlData) {
                    $_inferred[$key] = $urlData['url'];
                }
            }
            return array_merge(
                $_regular,
                $_inferred ? array_combine($_inferred, $_inferred) : []
            );
        }
    }

    /**
     * There are URLs and we're not in the middle of a crawl
     *
     * @return boolean
     */
    public function hasCrawled()
    {
        // if (SapphireTest::is_running_test()) {
        //     $this->cacheDir = BASE_PATH . '/staticsiteconnector/tests/static-site-1/';
        // }

        return file_exists($this->cacheDir . 'urls') && !file_exists($this->cacheDir . 'crawlerid');
    }

    /**
     * Load the URLs, either by crawling, or by fetching from cache.
     *
     * @return void
     * @throws \LogicException
     */
    public function loadUrls()
    {
        if ($this->hasCrawled()) {
            $this->urls = unserialize(file_get_contents($this->cacheDir . 'urls'));
            // Clear out obsolete format
            if (!isset($this->urls['regular']) || !isset($this->urls['inferred'])) {
                $this->urls = [
                    'regular' => [],
                    'inferred' => [],
                ];
            }
        } elseif ($this->autoCrawl) {
            $this->crawl();
        } else {
            // This happens if you move a cache-file out of the way during debugging...
            throw new \LogicException("Crawl hasn't been executed yet, and autoCrawl is set to false. Maybe a cache file has been moved?");
        }
    }

    /**
     * Re-execute the URL processor on all the fetched URLs.
     * If the site has been crawled and then subsequently the URLProcessor was changed, we need to ensure
     * URLs are re-processed using the newly selected URL Preprocessor.
     *
     * @return void
     */
    public function reprocessUrls()
    {
        if ($this->urls === null) {
            $this->loadUrls();
        }

        // Clear out all inferred URLs; these will be added
        $this->urls['inferred'] = [];

        // Reprocess URLs, in case the processing has changed since the last crawl
        foreach ($this->urls['regular'] as $url => $urlData) {
            $processedURLData = $this->generateProcessedURL($urlData);
            $this->urls['regular'][$url] = $processedURLData;

            // Trigger parent URL back-filling on new processed URL
            $this->parentProcessedURL($processedURLData);
        }

        $this->saveURLs();
    }

    /**
     *
     * @param number $limit
     * @param bool $verbose
     * @return StaticSiteCrawler
     * @throws Exception
     */
    public function crawl($limit = false, $verbose = false)
    {
        Environment::increaseTimeLimitTo(3600);

        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir)) {
                throw new \Exception('Unable to create cache directory at: ' . $this->cacheDir);
            }
        }

        $crawler = StaticSiteCrawler::create($this, $limit, $verbose);
        $crawler->enableResumption();
        $crawler->setUrlCacheType(PHPCrawlerUrlCacheTypes::URLCACHE_SQLITE);
        $crawler->setWorkingDirectory($this->cacheDir);

        // Find links in externally-linked CSS files
        if ($this->source->ParseCSS) {
            $crawler->addLinkSearchContentType("#text/css# i");
        }

        // Set some proxy options for phpCrawler
        singleton(StaticSiteUtils::class)->defineProxyOpts(!Director::isDev(), $crawler);

        // Allow for resuming an incomplete crawl
        if (file_exists($this->cacheDir . 'crawlerid')) {
            // We should re-load the partial list of URLs, if relevant
            // This should only happen when we are resuming a partial crawl
            if (file_exists($this->cacheDir . 'urls')) {
                $this->urls = unserialize(file_get_contents($this->cacheDir . 'urls'));
            } else {
                $this->urls = [
                    'regular' => [],
                    'inferred' => [],
                ];
            }

            $crawlerID = file_get_contents($this->cacheDir . 'crawlerid');
            $crawler->resume($crawlerID);
        } else {
            $crawlerID = $crawler->getCrawlerId();
            file_put_contents($this->cacheDir . '/crawlerid', $crawlerID);
            $this->urls = [
                'regular' => [],
                'inferred' => [],
            ];
        }

        $crawler->setURL($this->baseURL);
        $crawler->go();
        //$crawler->goMultiProcessedLinux();

        unlink($this->cacheDir . 'crawlerid');

        ksort($this->urls['regular']);
        ksort($this->urls['inferred']);

        $this->saveURLs();

        return $crawler;
    }

    /**
     * Cache the current list of URLs to disk.
     *
     * @return void
     */
    public function saveURLs()
    {
        file_put_contents($this->cacheDir . 'urls', serialize($this->urls));
    }

    /**
     * Add a URL to this list, given the absolute URL.
     *
     * @param string $url The absolute URL
     * @param string $content_type The Mime-Type found at this URL e.g text/html or image/png
     * @throws \InvalidArgumentException
     * @return void
     */
    public function addAbsoluteURL($url, $content_type)
    {
        $simplifiedURL = $this->simplifyURL($url);
        $simplifiedBase = $this->simplifyURL($this->baseURL);

        // Check we're adhering to the correct base URL
        if (substr($simplifiedURL, 0, strlen($simplifiedBase)) == $simplifiedBase) {
            $relURL = preg_replace("#https?://(www.)?[^/]+#", '', $url);
        } else {
            throw new \InvalidArgumentException("URL $url is not from the site $this->baseURL");
        }

        $this->addURL($relURL, $content_type);
    }

    /**
     * Appends a processed URL onto the URL cache.
     *
     * @param string $url
     * @param string $contentType
     * @return void
     */
    public function addURL($url, $contentType)
    {
        if ($this->urls === null) {
            $this->loadUrls();
        }

        // Generate and save the processed URLs
        $urlData = [
            'url' => $url,
            'mime' => $contentType,
        ];

        $this->urls['regular'][$url] = $this->generateProcessedURL($urlData);

        // Trigger parent URL back-filling
        $this->parentProcessedURL($this->urls['regular'][$url]);
    }

    /**
     * Add an inferred URL to the list.
     *
     * Since the unprocessed URL isn't available, we use the processed URL in its place.
     * This should be used with some caution.
     *
     * @param array $inferredURLData Contains the processed URL and Mime-Type to add
     * @return void
     */
    public function addInferredURL($inferredURLData)
    {
        if ($this->urls === null) {
            $this->loadUrls();
        }

        // Generate and save the processed URLs
        $this->urls['inferred'][$inferredURLData['url']] = $inferredURLData;

        // Trigger parent URL back-filling
        $this->parentProcessedURL($inferredURLData);
    }

    /**
     * Return true if the given URL exists.
     *
     * @param string $url The URL, either absolute, or relative starting with "/"
     * @return boolean Does the URL exist
     * @throws \InvalidArgumentException
     */
    public function hasURL($url)
    {
        if ($this->urls === null) {
            $this->loadUrls();
        }

        // Try and relativise an absolute URL
        if ($url[0] != '/') {
            $simpifiedURL = $this->simplifyURL($url);
            $simpifiedBase = $this->simplifyURL($this->baseURL);

            if (substr($simpifiedURL, 0, strlen($simpifiedBase)) == $simpifiedBase) {
                $url = substr($simpifiedURL, strlen($simpifiedBase));
            } else {
                throw new \InvalidArgumentException("URL $url is not from the site $this->baseURL");
            }
        }

        return isset($this->urls['regular'][$url]) || in_array($url, $this->urls['inferred']);
    }

    /**
     * Simplify a URL. Ignores https/http differences and "www." / non differences.
     *
     * @param  string $url
     * @return string
     */
    public function simplifyURL($url)
    {
        return preg_replace("#^http(s)?://(www.)?#i", 'http://www.', $url);
    }

    /**
     * Returns true if the given URL is in the list of processed URls
     *
     * @param string $processedURL The processed URL
     * @return boolean True if it exists, false otherwise
     */
    public function hasProcessedURL($processedURL)
    {
        if ($this->urls === null) {
            $this->loadUrls();
        }

        return in_array($processedURL, array_keys($this->urls['regular'])) ||
               in_array($processedURL, array_keys($this->urls['inferred']));
    }

    /**
     * Return the processed URL that is the parent of the given one.
     *
     * Both input and output are processed URLs
     *
     * @param array $processedURLData URLData comprising a relative URL and Mime-Type
     * @return string | array $processedURLData
     */
    public function parentProcessedURL($processedURLData)
    {
        $mime = self::$undefined_mime_type;
        $processedURL = $processedURLData;

        if (is_array($processedURLData)) {
            /*
             * If $processedURLData['url'] is not HTML, it's unlikely its parent
             * is anything useful (Prob just a directory)
             */
            $sng = singleton(StaticSiteMimeProcessor::class);
            $mime = $sng->IsOfHtml($processedURLData['mime']) ? $processedURLData['mime'] : self::$undefined_mime_type;
            $processedURL = $processedURLData['url'];
        }

        $default = function ($fragment) use ($mime) {
            return [
                'url' => $fragment,
                'mime' => $mime,
            ];
        };

        if ($processedURL == "/") {
            return $default('');
        }

        // URL hierarchy can be broken down by querystring or by URL
        $breakpoint = max(strrpos($processedURL, '?'), strrpos($processedURL, '/'));

        // Special case for children of the root
        if ($breakpoint == 0) {
            return $default('/');
        }

        // Get parent URL
        $parentProcessedURL = substr($processedURL, 0, $breakpoint);

        $processedURLData = [
            'url' => $parentProcessedURL,
            'mime' => $mime,
        ];

        // If an intermediary URL doesn't exist, create it
        if (!$this->hasProcessedURL($parentProcessedURL)) {
            $this->addInferredURL($processedURLData);
        }

        return $processedURLData;
    }

    /**
     * Find the processed URL in the URL list
     *
     * @param  mixed string | array $urlData
     * @return array $urlData
     */
    public function processedURL($urlData)
    {
        $url = $urlData;
        $mime = self::$undefined_mime_type;

        if (is_array($urlData)) {
            $url = $urlData['url'];
            $mime = $urlData['mime'];
        }

        if ($this->urls === null) {
            $this->loadUrls();
        }

        $urlData = [
            'url' => $url,
            'mime' => $mime,
        ];

        if (isset($this->urls['regular'][$url])) {
            // Generate it if missing
            if ($this->urls['regular'][$url] === true) {
                $this->urls['regular'][$url] = $this->generateProcessedURL($urlData);
            }

            return $this->urls['regular'][$url];
        } elseif (in_array($url, array_keys($this->urls['inferred']))) {
            return $this->urls['inferred'][$url];
        }
    }

    /**
     * Execute custom logic for processing URLs prior to heirachy generation.
     *
     * This can be used to implement logic such as ignoring the "/Pages/" parts of MOSS URLs, or dropping extensions.
     *
     * @param  array $urlData The unprocessed URLData
     * @return array $urlData The processed URLData
     * @throws \LogicException
     */
    public function generateProcessedURL($urlData)
    {
        $urlIsEmpty = (!$urlData || !isset($urlData['url']));

        if ($urlIsEmpty) {
            throw new \LogicException("Can't pass a blank URL to generateProcessedURL");
        }

        if ($this->urlProcessor) {
            $urlData = $this->urlProcessor->processURL($urlData);
        }

        if (!$urlData) {
            throw new \LogicException(get_class($this->urlProcessor) . " returned a blank URL.");
        }

        return $urlData;
    }

    /**
     * Return the URLs that are a child of the given URL
     *
     * @param string $url
     * @return array
     */
    public function getChildren($url)
    {
        if ($this->urls === null) {
            $this->loadUrls();
        }

        $processedURL = $this->processedURL($url);
        $processedURL = $processedURL['url'];

        // Subtly different regex if the URL ends in '?' or '/'
        if (preg_match('#[/?]$#', $processedURL)) {
            $regEx = '#^' . preg_quote($processedURL, '#') . '[^/?]+$#';
        } else {
            $regEx = '#^' . preg_quote($processedURL, '#') . '[/?][^/?]+$#';
        }

        $children = [];

        foreach ($this->urls['regular'] as $urlKey => $potentialProcessedChild) {
            $potentialProcessedChild = $urlKey;
            if (preg_match($regEx, $potentialProcessedChild)) {
                if (!isset($children[$potentialProcessedChild])) {
                    $children[$potentialProcessedChild] = $potentialProcessedChild;
                }
            }
        }

        foreach ($this->urls['inferred'] as $urlKey => $potentialProcessedChild) {
            $potentialProcessedChild = $urlKey;
            if (preg_match($regEx, $potentialProcessedChild)) {
                if (!isset($children[$potentialProcessedChild])) {
                    $children[$potentialProcessedChild] = $potentialProcessedChild;
                }
            }
        }

        return array_values($children);
    }

    /**
     * Simple property getter. Used in unit-testing.
     *
     * @param string $prop
     * @return mixed
     */
    public function getProperty($prop)
    {
        if ($this->$prop) {
            return $this->$prop;
        }
    }

    /**
     * Get the serialized cache content and return the unserialized string
     *
     * @todo implement to replace x3 refs to unserialize(file_get_contents($this->cacheDir . 'urls'));
     * @return string
     */
    public function getCacheFileContents()
    {
        $cache = '';
        $cacheFile = $this->cacheDir . 'urls';
        if (file_exists($cacheFile)) {
            $cache = unserialize(file_get_contents($cacheFile));
        }
        return $cache;
    }
}

use PHPCrawl\PHPCrawler;
use PHPCrawl\PHPCrawlerDocumentInfo;
use PHPCrawl\PHPCrawlerURLDescriptor;

/**
 * Extends PHPCrawler essentially to override its handleDocumentInfo() method.
 *
 * @see {@link PHPCrawler}
 */
class StaticSiteCrawler extends PHPCrawler
{
    use Injectable;
    use Configurable;

    /**
     *
     * @var array
     */
    protected $urlList;

    /**
     *
     * @var boolean
     */
    protected $verbose = false;

    /*
     * Holds the StaticSiteUtils object on construct
     *
     * @var Object
     */
    protected $utils;

    /**
     * Set this by using the yml config system
     *
     * Example:
     * <code>
     * StaticSiteContentExtractor:
     *	log_file:  ../logs/crawler-log.txt
     * </code>
     *
     * @var string
     */
    private static $log_file = null;

    /**
     *
     * @param StaticSiteUrlList $urlList
     * @param number $limit
     * @param boolean $verbose
     * @return void
     */
    public function __construct(StaticSiteUrlList $urlList, $limit = false, $verbose = false)
    {
        parent::__construct();

        $this->urlList = $urlList;
        $this->verbose = $verbose;

        if ($limit) {
            $this->setPageLimit($limit);
        }

        $this->utils = singleton(StaticSiteUtils::class);
    }

    /**
     * After checking raw status codes out of PHPCrawler we continue to save each URL to our cache file.
     *
     * $PageInfo gives us:
     *
     * $PageInfo->url
     * $PageInfo->http_status_code
     * $PageInfo->links_found_url_descriptors
     *
     * @param PHPCrawlerDocumentInfo $PageInfo
     * @return mixed null | void
     * @todo Can we make use of PHPCrawlerDocumentInfo#error_occured instead of manually checking server codes??
     * @todo The comments below state that badly formatted URLs never make it to our caching logic. Wrong.
     *	- Pass the preg_replace() call for "fixing" $mossBracketRegex into StaticSiteUrlProcessor#postProcessUrl()
     */
    public function handleDocumentInfo(PHPCrawlerDocumentInfo $PageInfo): int
    {
        $info = $PageInfo; // upgraded phpcrawler compatibility
        /*
         * MOSS has many URLs with brackets, e.g. http://www.stuff.co.nz/news/cat-stuck-up-tree/(/
         * These result in a 404 returned from curl requests for it, and won't filter down to our caching or URL Processor logic.
         * We can "recover" these URLs by stripping and replacing with a trailing slash. This allows us to be able to fetch all its child nodes, if present.
         */
        $mossBracketRegex = "(\(|%28)+(.+)?$";
        $isRecoverableUrl = preg_match("#$mossBracketRegex#i", $info->url);

        // Ignore errors and redirects, they'll get logged for later analysis
        $badStatusCode = (($info->http_status_code < 200) || ($info->http_status_code > 299));

        /*
         * We're checking for a bad status code AND for "recoverability", becuase we might be able to recover the URL
         * when re-requesting it during the import stage, as long as we cache it correctly here.
         */
        if ($badStatusCode && !$isRecoverableUrl) {
            $message = $info->url . " Skipped. We got a {$info->http_status_code} and URL was irrecoverable" . PHP_EOL;
            $this->utils->log($message);
            return 1;
        }

        // Continue building our cache
        $this->urlList->addAbsoluteURL($info->url, $info->content_type);

        // @todo is this needed?
        if ($this->verbose) {
            echo "[+] " . $info->url . PHP_EOL;
        }

        $this->urlList->saveURLs();

        return 0;
    }

    /**
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function initCrawlerProcess(): void
    {
        parent::initCrawlerProcess();

        // Add additional URLs to crawl to the crawler's LinkCache
        // NOTE: This is using an undocumented API
        if ($extraURLs = $this->urlList->getExtraCrawlURLs()) {
            foreach ($extraURLs as $extraURL) {
                $this->LinkCache->addUrl(new PHPCrawlerURLDescriptor($extraURL));
            }
        }

        // Prevent URLs that match the exclude patterns from being fetched
        if ($excludePatterns = $this->urlList->getExcludePatterns()) {
            foreach ($excludePatterns as $pattern) {
                $validRegExp = $this->addURLFilterRule('|' . str_replace('|', '\|', $pattern) . '|');
                if (!$validRegExp) {
                    throw new \InvalidArgumentException('Exclude url pattern "' . $pattern . '" is not a valid regular expression.');
                }
            }
        }
    }
}
