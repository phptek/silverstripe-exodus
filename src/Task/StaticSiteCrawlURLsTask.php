<?php

namespace PhpTek\Exodus\Task;

use SilverStripe\Dev\BuildTask;
use PhpTek\Exodus\Model\StaticSiteContentSource;
use SilverStripe\Core\Injector\Injectable;

/**
 *
 * @author Sam Minnee <sam@silverstripe.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteCrawlURLsTask extends BuildTask
{
    use Injectable;

    /**
     *
     * @param SS_HTTPRequest $request
     * @return null
     */
    public function run($request)
    {
        $id = $request->getVar('ID');
        if (!is_numeric($id) || !$id) {
            echo "<p>Specify ?ID=(number)</p>";
            return;
        }

        // Find all pages
        $contentSource = StaticSiteContentSource::get()->byID($id);
        $contentSource->urllist()->crawl(false, true);
    }
}
