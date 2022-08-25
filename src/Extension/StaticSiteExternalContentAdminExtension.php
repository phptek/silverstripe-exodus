<?php

namespace PhpTek\Exodus\Extension;

use ExternalContent;
use ExternalContentSource;
use PhpTek\Exodus\Model\StaticSiteImportDataObject;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\HTTPResponse;

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
        'crawlsite',
        'clearimports',
    ];

    /**
     * Controller method which starts a crawl.
     *
     * @param array $request
     * @throws Exception
     * @return HTTPResponse
     */
    public function crawlsite(array $request): HTTPResponse
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
            $message = _t('ExternalContent.CONTENTMIGRATED', 'Crawl successful. You can attempt an import now.');

            try {
                $source->crawl();
            } catch (\Exception $e) {
                $messageType = 'bad';
                $message = "Error crawling. Crawler said: " . $e->getMessage();
            }
        }

        $owner = $this->getOwner();
        $owner->getEditForm()->sessionError($message, $messageType);

        return $owner->getResponseNegotiator()->respond($owner->getRequest());
    }

    /**
     *
     * Delete all StaticSiteImportDataObject's and related logic.
     *
     * @param array $request
     * @return HTTPResponse
     */
    public function clearimports(array $request): HTTPResponse
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

        $owner = $this->getOwner();
        $owner->getEditForm()->sessionError($message, $messageType);

        return $owner->getResponseNegotiator()->respond($owner->getRequest());
    }
}
