<?php

namespace PhpTek\Exodus\Extension;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;

/**
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteDataExtension extends DataExtension
{
    /**
     *
     * @var array
     */
    private static $has_one = [
        "StaticSiteContentSource" => "StaticSiteContentSource",
    ];

    /**
     *
     * @var array
     */
    private static $db = [
        "StaticSiteURL" => "Varchar(255)",
        "StaticSiteImportID" => "Int,"
    ];

    /**
     * Show readonly fields of Import "Meta data"
     *
     * @param FieldList $fields
     * @return void
     */
    public function updateCMSFields(FieldList $fields)
    {
        if ($this->owner->StaticSiteContentSourceID && $this->owner->StaticSiteURL) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create('StaticSiteURL', 'Imported URL'), 'MenuTitle');
            $fields->addFieldToTab('Root.Main', $importField = ReadonlyField::create('StaticSiteImportID', 'Import ID'), 'MenuTitle');
            $importField->setDescription('Use this number to pass as the \'ImportID\' parameter to the StaticSiteRewriteLinksTask.');
        }
    }

    /**
     * Ensure related FailedURLRewriteObjects are also removed, when the related SiteTree
     * object is deleted in the CMS.
     *
     * @return void
     */
    public function onAfterDelete()
    {
        parent::onAfterDelete();
        if ($failedRewriteObjects = DataObject::get('FailedURLRewriteObject')->filter('ContainedInID', $this->owner->ID)) {
            $failedRewriteObjects->each(function ($item) {
                $item->delete();
            });
        }
    }
}
