<?php

namespace PhpTek\Exodus\Model;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * A model object that represents a single failed link-rewrite summary. This data is displayed
 * at the top of the {@link FailedURLRewriteReport}.
 *
 * @author Russell Michell <russ@silverstripe.com>
 * @package phptek/silverstripe-exodus
 */
class FailedURLRewriteSummary extends DataObject
{
    use Injectable;

    /**
     *
     * @var array
     */
    private static $db = [
        'Text' => 'Text',
        'ImportID' => 'Int',
    ];

    /**
     *
     * Format summary text so all totals are emboldened.
     *
     * @return string
     */
    public function getText()
    {
        return preg_replace("#([\d]*)#", "<strong>$1</strong>", $this->getField('Text'));
    }
}
