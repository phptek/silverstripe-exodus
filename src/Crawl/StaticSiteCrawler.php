<?php

namespace PhpTek\Exodus\Crawl;

use PHPCrawl\PHPCrawler;
use PHPCrawl\PHPCrawlerDocumentInfo;
use PHPCrawl\PHPCrawlerURLDescriptor;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Config\Configurable;
use PhpTek\Exodus\Tool\StaticSiteUrlList;
use PhpTek\Exodus\Tool\StaticSiteUtils;

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
     * @var StaticSiteUrlList
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
     * @var StaticSiteUtils
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
     * @return int
     * @todo Can we make use of PHPCrawlerDocumentInfo#error_occured instead of manually checking server codes??
     * @todo The comments below state that badly formatted URLs never make it to our caching logic. Wrong!
     *	- Pass the preg_replace() call for "fixing" $mossBracketRegex into StaticSiteUrlProcessor#postProcessUrl()
     * @todo Processor-specific logic (MOSS) should be ported into dedicated class under "Process" namespace
     */
    public function handleDocumentInfo(PHPCrawlerDocumentInfo $PageInfo): int
    {
        $info = $PageInfo; // upgraded phpcrawler compatibility
        /*
         * MOSS has many URLs with brackets, e.g. http://www.stuff.co.nz/news/cat-stuck-up-tree/(/
         * These result in 4xx response-codes returned from curl requests for it, and won't filter down to our
         * caching or URL Processor logic. We can "recover" these URLs by stripping and replacing
         * with a trailing slash. This allows us to be able to fetch all the URL's children, if any.
         */
        $isRecoverableUrl = (bool) preg_match('#(\(|%28)+(.+)?$#i', $info->url);
        // Ignore errors and redirects, they'll get logged for later analysis
        $badStatusCode = (($info->http_status_code < 200) || ($info->http_status_code > 299));
        /*
         * We're checking for a bad status code AND for "recoverability", becuase we might be able to recover the URL
         * when re-requesting it during the import stage, as long as we cache it correctly here.
         */
        if ($badStatusCode && !$isRecoverableUrl) {
            $message = $info->url . " Skipped. We got a bad status-code and URL was irrecoverable" . PHP_EOL;
            $this->utils->log($message);

            return 1;
        }

        // Continue building our cache
        $this->urlList->addAbsoluteURL($info->url, $info->content_type);
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
