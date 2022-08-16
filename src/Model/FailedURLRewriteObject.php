<?php

namespace PhpTek\Exodus\Model;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBVarchar;

/**
 * A model object that represents a single failed link-rewrite during the
 * running of the StaticSiteRewriteLinksTask. This data is then used to power the
 * {@link FailedURLRewriteReport}.
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 * @see {@link StaticSiteLinkRewriteTask}
 */
class FailedURLRewriteObject extends DataObject
{
    use Injectable;

    /**
     * @var string
     */
    private static $table_name = 'FailedURLRewriteObject';

    /**
     *
     * @var array
     */
    private static $db = [
        "BadLinkType" => "Enum('ThirdParty, BadScheme, NotImported, Junk, Unknown', 'Unknown')",
        "OrigUrl" => DBVarchar::class,
    ];

    /**
     *
     * @var array
     */
    private static $has_one = [
        'Import' => StaticSiteImportDataObject::class,
        'ContainedIn' => SiteTree::class,
    ];

    /**
     * Customise the output of the FailedURLRewriteReport CSV export.
     *
     * @return array
     */
    public function summaryFields()
    {
        return [
            'ContainedIn.Title' => 'Imported page',
            'Import.Created' => 'Import date',
            'ThirdPartyTotal' => 'No. 3rd Party Urls',
            'BadSchemeTotal' => 'No. Urls with bad-scheme',
            'NotImportedTotal' => 'No. Unimported Urls',
            'JunkTotal' => 'No. Junk Urls',
        ];
    }

    /**
     * Fetch the related SiteTree object's Title property.
     *
     * @return string
     */
    public function Title()
    {
        return $this->ContainedIn()->Title;
    }

    /**
     * Get totals for each type of failed URL.
     *
     * @param number $importID
     * @param string $badLinkType e.g. 'NotImported'
     * @return SS_List
     */
    public function getBadImportData($importID, $badLinkType = null)
    {
        $default = ArrayList::create();

        $badLinks = DataObject::get(__CLASS__)
                ->filter('ImportID', $importID)
                ->sort('Created');

        if ($badLinks) {
            if ($badLinkType) {
                return $badLinks->filter('BadLinkType', $badLinkType);
            }
            return $badLinks;
        }

        return $default;
    }
}
