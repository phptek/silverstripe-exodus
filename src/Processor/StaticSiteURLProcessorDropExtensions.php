<?php

namespace PhpTek\Exodus\Processor;

use PhpTek\Exodus\Tool\StaticSiteUrlProcessor;
use SilverStripe\Core\Injector\Injectable;

/**
 * Processor for MOSS Standard-URLs while dropping file extensions
 */
class StaticSiteURLProcessorDropExtensions implements StaticSiteUrlProcessor
{
    use Injectable;

    /**
     *
     * @return string
     */
    public function getName(): string
    {
        return "Simple clean-up (recommended)";
    }

    /**
     *
     * @return string
     */
    public function getDescription(): string
    {
        return "Removes extensions and trailing slashes.";
    }

    /**
     *
     * @param array $urlData
     * @return array
     */
    public function processURL(array $urlData): array
    {
        if (!is_array($urlData) || empty($urlData['url'])) {
            return [];
        }

        $url = '';

        // With query string
        if (preg_match("#^([^?]*)\?(.*)$#", $urlData['url'], $matches)) {
            $url = $matches[1];
            $qs = $matches[2];
            $url = preg_replace("#\.[^./?]*$#", "$1", $url);
            $url = $this->postProcessUrl($url);

            return [
                'url' => "$url?$qs",
                'mime' => $urlData['mime'],
            ];
        } else {
            // No query string
            $url = $urlData['url'];
            $url = preg_replace("#\.[^./?]*$#", "$1", $url);

            return [
                'url' => $this->postProcessUrl($url),
                'mime' => $urlData['mime'],
            ];
        }
    }

    /**
     * Post-processes urls for common issues like encoded brackets and slashes that we wish to apply to all URL
     * Processors.
     *
     * @param string $url
     * @return string
     * @todo Instead of testing for arbitrary URL irregularities,
     * can we not just clean-out chars that don't adhere to HTTP1.1 or the appropriate RFC?
     */
    private function postProcessUrl(string $url): string
    {
        // Replace all encoded slashes with non-encoded versions
        $noSlashes = str_ireplace('%2f', '/', $url);
        // Replace all types of brackets
        $noBrackets = str_replace(array('%28', '(', ')'), '', $noSlashes);
        // Return, ensuring $url never has >1 consecutive slashes e.g. /blah//test
        return preg_replace("#[^:]/{2,}#", '/', $noBrackets);
    }
}
