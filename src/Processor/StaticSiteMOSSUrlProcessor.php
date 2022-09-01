<?php

namespace PhpTek\Exodus\Processor;

use PhpTek\Exodus\Tool\StaticSiteUrlProcessor;
use PhpTek\Exodus\Processor\StaticSiteURLProcessorDropExtensions;

/**
 * Processor for MOSS URLs (Microsoft Office Sharepoint Server)
 * @todo Move into a "Processors" namespace
 */
class StaticSiteMOSSURLProcessor extends StaticSiteURLProcessorDropExtensions implements StaticSiteUrlProcessor
{
    /**
     *
     * @return string
     */
    public function getName(): string
    {
        return "MOSS-style URLs";
    }

    /**
     *
     * @return string
     */
    public function getDescription(): string
    {
        return "Removes '/Pages/' from URIs, removes extensions and trailing slashes.";
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

        $url = str_ireplace('/Pages/', '/', $urlData['url']);
        $urlData = [
            'url' => $url,
            'mime' => $urlData['mime'],
        ];

        return parent::processURL($urlData);
    }
}
