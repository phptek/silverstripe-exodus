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
        return "Microsoft Sharepoint Processor";
    }

    /**
     *
     * @return string
     */
    public function getDescription(): string
    {
        return "Removes '/Pages/',  file-extensions and trailing slashes from URIs.";
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
