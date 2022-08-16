<?php

namespace PhpTek\Exodus\Model;

use PhpTek\Exodus\Model\FailedURLRewriteObject;
use PhpTek\Exodus\Model\FailedURLRewriteSummary;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Caches some metadata for each import. Allows imports to have a DataObject-like functionality.
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 * @see {@link StaticSiteImporter}
 */
class StaticSiteImportDataObject extends DataObject
{
    use Injectable;

    /**
     * @var string
     */
    private static $table_name = 'StaticSiteImportDataObject';

    /**
     *
     * @var array
     */
    private static $db = [
        'Ended' => DBDatetime::class,
    ];

    /**
     *
     * @var array
     */
    private static $has_one = [
        'User' => Member::class,
    ];

    /**
     * Get the most recently started/run import.
     *
     * @param Member $member
     * @return null | DataList
     */
    public static function current($member = null)
    {
        $import = StaticSiteImportDataObject::get()
                ->sort('Created')
                ->last();

        if ($import && $member) {
            return $import->filter('UserID', $member->ID);
        }

        return $import;
    }

    /**
     * To be called at the start of an import.
     *
     * @return StaticSiteImportDataObject
     */
    public function start()
    {
        $this->UserID = Security::getCurrentUser()->getField('ID');
        $this->write();

        return $this;
    }

    /**
     * To be called at the end of an import.
     *
     * @return StaticSiteImportDataObject
     */
    public function end()
    {
        $this->Ended = DBDatetime::now()->getValue();
        $this->write();

        return $this;
    }

    /**
     * Make sure related FailedURLRewriteObject's are also removed
     *
     * @todo Would belongs_to() do the job?
     * @return void
     */
    public function onAfterDelete()
    {
        parent::onAfterDelete();

        $relatedFailedRewriteObjects = DataObject::get(FailedURLRewriteObject::class)->filter('ImportID', $this->ID);
        $relatedFailedRewriteSummaries = DataObject::get(FailedURLRewriteSummary::class)->filter('ImportID', $this->ID);

        $relatedFailedRewriteObjects->each(function ($item) {
            $item->delete();
        });

        $relatedFailedRewriteSummaries->each(function ($item) {
            $item->delete();
        });
    }
}
