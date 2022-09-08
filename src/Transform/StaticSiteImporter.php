<?php

namespace PhpTek\Exodus\Transform;

use ExternalContentImporter;
use PhpTek\Exodus\Transform\StaticSitePageTransformer;
use PhpTek\Exodus\Model\StaticSiteImportDataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\TaskRunner;
use SilverStripe\Control\HTTPRequest;
use PhpTek\Exodus\Task\StaticSiteRewriteLinksTask;
use SilverStripe\Core\Injector\Injector;

/**
 * Physically brings content into SilverStripe as defined by URLs fetched
 * at the crawl stage, and utilises {@link StaticSitePageTransformer} and {@link StaticSiteFileTransformer}.
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 * @see {@link ExternalContentImporter}
 * @see {@link StaticSiteImportDataObject}
 */
class StaticSiteImporter extends ExternalContentImporter
{
    /**
     *
     * @return void
     */
    public function __construct()
    {
        $this->contentTransforms['sitetree'] = StaticSitePageTransformer::create();
        $this->contentTransforms['file'] = StaticSiteFileTransformer::create();
    }

    /**
     *
     * @param $item
     * @return string
     * @todo `$item` param seems to be instance of `ExternalContentSource` not `ExternalContentItem` for some reason
     */
    public function getExternalType($item)
    {
        return $item->getType();
    }

    /**
     * Run prior to the entire import process starting.
     *
     * Creates an import DataObject record for hooking-into later with the link-processing logic.
     *
     * @return void
     */
    public function runOnImportStart(): void
    {
        parent::runOnImportStart();

        StaticSiteImportDataObject::create()->start();
    }

    /**
     * Run right when the import process ends.
     *
     * @return void
     */
    public function runOnImportEnd()
    {
        parent::runOnImportEnd();
        $current = StaticSiteImportDataObject::current();
        $current->end();

        $importID = $current->ID;
        $this->runRewriteLinksTask($importID);
    }

    /**
     *
     * @param number $importID
     * @return void
     * @todo How to interject with external-content's "Import Complete" message to only show when
     * this method has completed?
     * @todo Use the returned task output, and display on-screen deploynaut style
     */
    protected function runRewriteLinksTask($importID)
    {
        $params = Controller::curr()->getRequest()->postVars();
        $sourceID = !empty($params['ID']) ? $params['ID'] : 0;
        $autoRun = !empty($params['AutoRunTask']) ? $params['AutoRunTask'] : null;

        if ($sourceID && $autoRun) {
            $task = TaskRunner::create();
            $getVars = [
                'SourceID' => $sourceID,
                'ImportID' => $importID,
                'SilentRun' => 1
            ];

            // Skip TaskRunner. Too few docs available on its use
            $request = new HTTPRequest('GET', '/dev/tasks/StaticSiteRewriteLinksTask', $getVars);
            $inst = Injector::inst()->create(StaticSiteRewriteLinksTask::class);
            $inst->run($request);
        }
    }
}
