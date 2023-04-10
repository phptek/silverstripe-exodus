<?php

namespace PhpTek\Exodus\Task;

use PhpTek\Exodus\Model\StaticSiteContentSource;
use SilverStripe\Dev\BuildTask;

/**
 *
 * @author Sam Minnee <sam@silverstripe.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteCrawlURLsTask extends BuildTask
{
    /**
     *
     * @param HTTPRequest $request
     * @return null
     */
    public function run($request)
    {
        $id = $request->getVar('ID');

        if (!$id or !is_numeric($id)) {
            return null;
        }

        // Find all pages
        $contentSource = StaticSiteContentSource::get()->byID($id);
        $contentSource->urllist()->crawl(false, true);

        return null;
    }
}
