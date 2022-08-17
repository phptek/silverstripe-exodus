<?php

namespace PhpTek\Exodus\Extension;

use \ExternalContent;
use \ExternalContentSource;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\HTTPRequest;

/**
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteExternalContentAdminExtension extends Extension
{

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
       // Requirements::css('phptek/silverstripe-exodus:css/StaticSiteConnector.css');
    }

    /**
     *
     * @param array $data
     * @throws Exception
     * @return HTTPResponse
     */
    public function crawlsite(array $request)
    {
        $selected = $request['ID'] ?? 0;

        if (!$selected) {
            $messageType = 'bad';
            $message = _t('ExternalContent.NOITEMSELECTED', 'No item selected to crawl.');
        } else {
            $source = ExternalContent::getDataObjectFor($selected);

            if (!($source instanceof ExternalContentSource)) {
                throw new \Exception('ExternalContent is not an instance of ExternalContentSource.');
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

        $session = $this->getOwner()->getRequest()->getSession();
        $session->set("FormInfo.Form_EditForm.formError.message", $message);
        $session->set("FormInfo.Form_EditForm.formError.type", $messageType);

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    /**
     *
     * Delete all StaticSiteImportDataObject's and related logic.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
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

        $session = $this->getOwner()->getRequest()->getSession();
        $session->set("FormInfo.Form_EditForm.formError.message", $message);
        $session->set("FormInfo.Form_EditForm.formError.type", $messageType);

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }
}
