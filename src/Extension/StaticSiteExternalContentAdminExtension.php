<?php

namespace PhpTek\Exodus\Extension;

use \ExternalContent;
use \ExternalContentSource;
use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteExternalContentAdminExtension extends Extension
{
    use Injectable;

    /**
     *
     * @var array
     */
    private static $allowed_actions = [
        "crawlsite",
        "clearimports",
    ];

    /**
     * Load module-wide CSS
     *
     * @return void
     * @todo Is this needed?
     */
    public function init()
    {
        Requirements::css('staticsiteconnector/css/StaticSiteConnector.css');
    }

    /**
     *
     * @param SS_HTTPRequest $request
     * @throws Exception
     * @return SS_HTTPResponse
     */
    public function crawlsite($request)
    {
        $selected = isset($request['ID']) ? $request['ID'] : 0;
        if (!$selected) {
            $messageType = 'bad';
            $message = _t('ExternalContent.NOITEMSELECTED', 'No item selected to crawl.');
        } else {
            $source = ExternalContent::getDataObjectFor($selected);
            if (!($source instanceof ExternalContentSource)) {
                throw new \Exception('ExternalContent is not instance of ExternalContentSource.');
            }

            $messageType = 'good';
            $message = _t('ExternalContent.CONTENTMIGRATED', 'Crawling successful.');

            try {
                $source->crawl();
            } catch (\Exception $e) {
                $messageType = 'bad';
                $message = "Error crawling: " . $e->getMessage();
            }
        }

        Session::set("FormInfo.Form_EditForm.formError.message", $message);
        Session::set("FormInfo.Form_EditForm.formError.type", $messageType);

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    /**
     *
     * Delete all StaticSiteImportDataObject's and related logic.
     *
     * @param SS_HTTPRequest $request
     * @return SS_HTTPResponse
     */
    public function clearimports($request)
    {
        if (!empty($request['ClearAllImports'])) {
            $imports = DataObject::get(StaticSiteImportDataObject::class);
        } elseif ($selectedImports = $request['ShowImports']) {
            $imports = DataObject::get(StaticSiteImportDataObject::class)->byIDs($selectedImports);
        } else {
            $imports = null;
        }

        if ($imports) {
            $imports->each(function ($item) {
                $item->delete();
            });
            $messageType = 'good';
            $message = _t('StaticSiteConnector.ImportsDeleted', 'Selected imports were cleared successfully.');
        } else {
            $messageType = 'bad';
            $message = _t('StaticSiteConnector.ImportsDeleted', 'No imports were selected to clear.');
        }

        Session::set("FormInfo.Form_EditForm.formError.message", $message);
        Session::set("FormInfo.Form_EditForm.formError.type", $messageType);

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }
}
