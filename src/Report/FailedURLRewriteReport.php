<?php

namespace PhpTek\Exodus\Report;

use PhpTek\Exodus\Model\FailedURLRewriteObject;
use PhpTek\Exodus\Model\FailedURLRewriteSummary;
use PhpTek\Exodus\Model\StaticSiteImportDataObject;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Reports\Report;
use SilverStripe\GraphQL\Controller;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;

/**
 * A CMS report for URLs that failed to be re-written.
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 * @see {@link FailedURLRewriteObject}
 * @see {@link FailedURLRewriteSummary}
 * @see {@link StaticSiteRewriteLinksTask}
 */
class FailedURLRewriteReport extends Report
{
    use Injectable;

    /**
     *
     * @var string
     */
    protected $description = <<<'TXT'
This report shows a record for each page that contains one or more broken links, left over from the selected import.
<br/>You can manually delete a record as you go through and correct the links in each one.
TXT;

    /**
     *
     * @return string
     */
    public function title()
    {
        return "Imported links rewrite report";
    }

    /**
     *
     * @return ArrayList
     * @todo refactor this and use another, generic method to deal with repeated (similar) conditionals.
     */
    public function SourceRecords()
    {
        $reqVars = Controller::curr()->request->requestVars();
        $importID = !empty($reqVars['filters']) ? $reqVars['filters']['ImportID'] : 1;
        $list = singleton(FailedURLRewriteObject::class)->getBadImportData($importID);
        $_list = ArrayList::create();
        $countNotImported = $countJunk = $countThirdParty = $countBadScheme = [];
        foreach ($list as $badLink) {
            if ($badLink->BadLinkType == 'NotImported') {
                // Prevent same page showing in the report and "sum" the totals
                if (empty($countNotImported[$badLink->ContainedInID])) {
                    $countNotImported[$badLink->ContainedInID] = 1;
                } else {
                    $countNotImported[$badLink->ContainedInID] += 1;
                }
                continue;
            }
            if ($badLink->BadLinkType == 'ThirdParty') {
                // Prevent same page showing in the report and "sum" the totals
                if (empty($countThirdParty[$badLink->ContainedInID])) {
                    $countThirdParty[$badLink->ContainedInID] = 1;
                } else {
                    $countThirdParty[$badLink->ContainedInID] += 1;
                }
            }
            if ($badLink->BadLinkType == 'BadScheme') {
                // Prevent same page showing in the report and "sum" the totals
                if (empty($countBadScheme[$badLink->ContainedInID])) {
                    $countBadScheme[$badLink->ContainedInID] = 1;
                } else {
                    $countBadScheme[$badLink->ContainedInID] += 1;
                }
                continue;
            }
            if ($badLink->BadLinkType == 'Junk') {
                // Prevent same page showing in the report and "sum" the totals
                if (empty($countJunk[$badLink->ContainedInID])) {
                    $countJunk[$badLink->ContainedInID] = 1;
                } else {
                    $countJunk[$badLink->ContainedInID] += 1;
                }
                continue;
            }
        }

        foreach ($list as $item) {
            // Only push new items if not already in the list
            if (!$_list->find('ContainedInID', $item->ContainedInID)) {
                $item->ThirdPartyTotal = isset($countThirdParty[$item->ContainedInID]) ? $countThirdParty[$item->ContainedInID] : 0;
                $item->BadSchemeTotal = isset($countBadScheme[$item->ContainedInID]) ? $countBadScheme[$item->ContainedInID] : 0;
                $item->NotImportedTotal = isset($countNotImported[$item->ContainedInID]) ? $countNotImported[$item->ContainedInID] : 0;
                $item->JunkTotal = isset($countJunk[$item->ContainedInID]) ? $countJunk[$item->ContainedInID] : 0;
                $_list->push($item);
            }
        }
        return $_list;
    }

    /**
     * Get the columns to show with header titles.
     *
     * @return array
     */
    public function columns()
    {
        return [
            'Title' => [
                'title' => 'Imported page',
                'formatting' => function ($value, $item) {
                    return sprintf(
                        '<a href="admin/pages/edit/show/%s">%s</a>',
                        $item->ContainedInID,
                        $item->Title()
                    );
                }
            ],
            'ThirdPartyTotal' => [
                'title' => '# 3rd Party Urls',
                'formatting' => '".$ThirdPartyTotal."'
            ],
            'BadSchemeTotal' => [
                'title' => '# Urls w/bad-scheme',
                'formatting' => '".$BadSchemeTotal."'
            ],
            'NotImportedTotal' => [
                'title' => '# Unimported Urls',
                'formatting' => '".$NotImportedTotal."'
            ],
            'JunkTotal' => [
                'title' => '# Junk Urls',
                'formatting' => '".$JunkTotal."'
            ],
            'Created' => [
                'title' => 'Task run date',
                'casting' => 'DBDatetime->Time24'
            ],
            'Import.Created' => [
                'title' => 'Import date',
                'casting' => 'DBDatetime->Time24'
            ]
        ];
    }

    /**
     * Get link-rewrite summary for display at the top of the report.
     * The data itself comes from a DataList of FailedURLRewriteObject's.
     *
     * @param number $importID
     * @return null | string
     */
    protected function getSummary($importID)
    {
        if (!$text = DataObject::get_one(FailedURLRewriteSummary::class, "\"ImportID\" = '$importID'")) {
            return;
        }

        $lines = explode(PHP_EOL, $text->Text);
        $summaryData = '';
        foreach ($lines as $line) {
            $summaryData .= $line . '<br/>';
        }
        return $summaryData;
    }

    /**
     * Show a basic form that allows users to filter link-rewrite data according to
     * a specific import propogate via query-string.
     *
     * @return FieldList
     */
    public function parameterFields()
    {
        $fields = FieldList::create();
        $reqVars = Controller::curr()->request->requestVars();
        $importID = !empty($reqVars['filters']) ? $reqVars['filters']['ImportID'] : 1;

        if ($summary = $this->getSummary($importID)) {
            $fields->push(HeaderField::create('SummaryHead', 'Summary', 4));
            $fields->push(LiteralField::create('SummaryBody', $summary));
        }

        $source = DataObject::get(StaticSiteImportDataObject::class);
        $_source = [];
        foreach ($source as $import) {
            $date = DBField::create_field(DBDatetime::class, $import->Created)->Time24();
            $_source[$import->ID] = $date . ' (Import #' . $import->ID . ')';
        }

        $importDropdown = LiteralField::create('ImportID', '<p>No imports found.</p>');
        if ($_source) {
            $importDropdown = DropdownField::create('ImportID', 'Import selection', $_source);
        }

        $fields->push($importDropdown);
        return $fields;
    }

    /**
     * Overrides SS_Report::getReportField() with the addition of GridField Actions.
     *
     * @return GridField
     */
    public function getReportField()
    {
        $gridField = parent::getReportField();
        $gridField->setModelClass('FailedURLRewriteObject');
        $config = $gridField->getConfig();
        $config->addComponent(new GridFieldDeleteAction());

        return $gridField;
    }
}
