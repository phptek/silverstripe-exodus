<?php

namespace PhpTek\Exodus\Tool;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use PhpTek\Exodus\Crawl\StaticSiteCrawler;

/**
 * Exodus utility methods.
 *
 * @package phptek/silverstripe-exodus
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteUtils
{
    use Injectable;
    use Configurable;

    /**
     * Log a message if the logging has been setup according to docs
     *
     * @param string $message
     * @param string $filename
     * @param string $mime
     * @return void
     */
    public function log(string $message, string $filename = '', string $mime = ''): void
    {
        $logFile = $filename ?: $this->config()->get('log_file');

        if ($logFile) {
            $message = $message . ($filename ? ' ' . $filename : '') . ($mime ? ' (' . $mime . ')' : '');
            file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * If operating in a specific environment, set some proxy options for it for passing to curl and
     * to phpCrawler (if set in config).
     *
     * @param boolean $set e.g. !Director::isDev()
     * @param StaticSiteCrawler $crawler (Warning: Pass by reference)
     * @return array Returns an array of the config options in a format consumable by curl.
     */
    public function defineProxyOpts(bool $set, StaticSiteCrawler &$crawler = null): array
    {
        if ($set && is_bool($set) && $set !== false) {
            $proxyOpts = StaticSiteContentExtractor::config()->get('curl_opts_proxy');

            if (!$proxyOpts || !is_array($proxyOpts) || !count($proxyOpts) >0) {
                return [];
            }

            if ($crawler) {
                $crawler->setProxy($proxyOpts['hostname'], $proxyOpts['port']);
            }

            return [
                CURLOPT_PROXY => $proxyOpts['hostname'],
                CURLOPT_PROXYPORT => $proxyOpts['port']
            ];
        }

        return [];
    }
}
