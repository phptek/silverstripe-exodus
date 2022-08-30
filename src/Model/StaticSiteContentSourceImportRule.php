<?php

namespace PhpTek\Exodus\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\FieldType\DBBoolean;

/**
 * A single import rule that forms part of an ImportSchema
 */
class StaticSiteContentSourceImportRule extends DataObject
{
    /**
     *
     * @var array
     */
    private static $db = [
        "FieldName" => DBVarchar::class,
        "CSSSelector" => DBText::class,
        "ExcludeCSSSelector" => DBText::class,
        "Attribute" => DBVarchar::class,
        "PlainText" => DBBoolean::class,
        "OuterHTML" => DBBoolean::class,
    ];

    /**
     * @var string
     */
    private static $table_name = 'StaticSiteContentSourceImportRule';

    /**
     *
     * @var array
     */
    private static $summary_fields = [
        "FieldName",
        "CSSSelector",
        "Attribute",
        "PlainText",
        "OuterHTML",
    ];

    /**
     *
     * @var array
     */
    private static $field_labels = [
        "FieldName" => "Target Field Name",
        "CSSSelector" => "CSS Selector(s)",
        'ExcludeCSSSelector' => 'Excluded CSS Selector(s)',
        "Attribute" => "Element Attribute",
        "PlainText" => "Convert to plain text?",
        "OuterHTML" => "Use outer HTML?",
    ];

    /**
     *
     * @var array
     */
    private static $has_one = [
        "Schema" => StaticSiteContentSourceImportSchema::class,
    ];

    /**
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->FieldName ?: $this->ID;
    }

    /**
     *
     * @return string
     */
    public function getAbsoluteURL()
    {
        return $this->URLSegment ?: $this->Filename;
    }

    /**
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $dataType = $this->Schema()->DataType;

        if ($dataType) {
            $fieldList = singleton(DataObjectSchema::class)
                ->fieldSpecs($dataType, DataObjectSchema::DB_ONLY);
            $fieldList = array_combine(array_keys($fieldList), array_keys($fieldList));
            $exclusions = array_merge(
                array_keys(DataObject::config()->get('fixed_fields')),
                // TODO make this a regex ala #ID$##
                [
                    'ParentID',
                    'WorkflowDefinitionID',
                    'Version',
                ]
            );

            foreach (array_combine($exclusions, $exclusions) as $exclusion) {
                unset($fieldList[$exclusion]);
            }

            sort($fieldList);

            $fieldNameField = DropdownField::create("FieldName", 'Target Field', $fieldList)
                ->setEmptyString("(choose)")
                ->setDescription('Source content matched by the CSS selector(s) below is written to this field ');
            $fields->insertBefore($fieldNameField, 'CSSSelector');
            $fields->dataFieldByName('CSSSelector')
                ->setDescription('A list of valid CSS selectors (separated by a space) whose content'
                . ' is written to the "Target Field" above');
            $fields->dataFieldByName('ExcludeCSSSelector')
                ->setDescription('A list of valid CSS selectors (separated by a space) whose content'
                . ' should be ignored. This is useful for fine-tuning what is returned in an import.');
        } else {
            $fields->replaceField('FieldName', $fieldName = ReadonlyField::create("FieldName", "Field Name"));
            $fieldName->setDescription('Save this rule before being able to add a field name');
        }

        $fields->dataFieldByName('Attribute')->setDescription('Add the element attribute where the desired text'
        . ' can be found (such as "alt" or "title") if not found as the selected element\'s text itself.');

        return $fields;
    }
}
