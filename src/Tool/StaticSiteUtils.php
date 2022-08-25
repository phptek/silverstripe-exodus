<?php

namespace PhpTek\Exodus\Tool;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;

/**
 * Basic class for utility methods unsuited to any other class.
 *
 * @package phptek/silverstripe-exodus
 * @author Russell Michell <russell@silverstripe.com>
 * @todo Should these methods all be statics?
 */
class StaticSiteUtils
{
    use Injectable;

    /**
     * Log a message if the logging has been setup according to docs
     *
     * @param string $message
     * @param string $filename
     * @param string $mime
     * @return null | void
     */
    public function log($message, $filename = null, $mime = null)
    {
        $logFile = '/tmp/exodus_extract.log';

        if (is_writable($logFile) || !file_exists($logFile) && is_writable(dirname($logFile))) {
            $message = $message . ($filename ? ' ' . $filename : '') . ($mime ? ' (' . $mime . ')' : '');
            error_log($message. PHP_EOL, 3, $logFile);
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
    public function defineProxyOpts($set, &$crawler = null)
    {
        if ($set && is_bool($set) && $set !== false) {
            $proxyOpts = StaticSiteContentExtractor::config()->get('curl_opts_proxy');
            if (!$proxyOpts || !is_array($proxyOpts) || !count($proxyOpts)>0) {
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
