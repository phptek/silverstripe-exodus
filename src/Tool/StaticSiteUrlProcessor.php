<?php

namespace PhpTek\Exodus\Tool;

use SilverStripe\Core\Injector\Injectable;
use PhpTek\Exodus\Processor\StaticSiteURLProcessorDropExtensions;

/**
 * Interface for building URL processing plug-ins for {@link StaticSiteUrlList}.
 *
 * The URL processing plugins are used to process the relative URL before it's used for two separate purposes:
 *
 *  1. Generating default URL and Title in the external content browser
 *  2. Building the content hierarchy
 *
 * For example, MOSS has a habit of putting unnecessary "/Pages/" elements into the URLs, and adding
 * .aspx extensions. We don't want to include these in the content hierarchy.
 *
 * More sophisticated processing might be done to facilitate importing of less.
 *
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 */
interface StaticSiteUrlProcessor
{
    /**
     * Return a name for the style of URLs to be processed.
     * This name will be shown in the CMS when users are configuring the content import.
     *
     * @return string The name in plaintext (no markup)
     */
    public function getName();

    /**
     * Return an explanation of what processing is done.
     * This explanation will be shown in the CMS when users are configuring the content import.
     *
     * @return string The description in plaintext (no markup)
     */
    public function getDescription();

    /**
     * Return a description for this processor to be shown in the CMS.
     *
     * @param array $urlData The unprocessed URL and mime-type as returned from {@link PHPCrawl}.
     * @return array An array comprising a processed URL and its Mime-Type
     */
    public function processURL(array $urlData);
}
