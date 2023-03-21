<?php

namespace PhpTek\Exodus\Model;

use Page;
use PhpTek\Exodus\Tool\StaticSiteMimeProcessor;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldAddExistingAutocompleter;
use SilverStripe\Assets\File;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Dev\Debug;

/**
 * Represents a single import-rule that applies to some or all of the content to be imported.
 */
class StaticSiteContentSourceImportSchema extends DataObject
{
    /**
     * Default
     *
     * @var string
     */
    private static $default_applies_to = '.*';

    /**
     * @var string
     */
    private static $table_name = 'StaticSiteContentSourceImportSchema';

    /**
     *
     * @var array
     */
    private static $db = [
        "DataType" => DBVarchar::class,
        "Order" => DBInt::class,
        "AppliesTo" => DBVarchar::class,
        "MimeTypes" => DBText::class,
        "Notes" => DBText::class,	// Purely informational. Not used in imports.
    ];

    /**
     *
     * @var array
     */
    private static $summary_fields = [
        "AppliesTo",
        "DataType",
        "Order",
    ];

    /**
     *
     * @var array
     */
    private static $field_labels = [
        "AppliesTo" => "URL Pattern",
        "DataType" => "Data Type",
        "Order" => "Priority",
        "MimeTypes"	=> "Mime-types",
    ];

    /**
     *
     * @var string
     */
    private static $default_sort = "Order";

    /**
     *
     * @var array
     */
    private static $has_one = [
        "ContentSource" => StaticSiteContentSource::class,
    ];

    /**
     *
     * @var array
     */
    private static $has_many = [
        "ImportRules" => StaticSiteContentSourceImportRule::class,
    ];

    /**
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->DataType . ' (' .$this->AppliesTo . ')';
    }

    /**
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeFieldFromTab('Root.Main', 'DataType');
        $fields->removeByName('ContentSourceID');
        $dataObjects = ClassInfo::subclassesFor(DataObject::class);

        array_shift($dataObjects);
        natcasesort($dataObjects);

        $fields->insertBefore('Order', LiteralField::create(
            'ImportIntro',
            ''
            . '<p class="message notice">An Import Schema maps a source URI regex and Mime-Type'
            . ' to a target content-type (Usually a SiteTree subclass, but could be an ElementalBlock).'
            . ' Content that matches will be saved as the selected Data Type.</p>'
        ));

        $fields->dataFieldByName('AppliesTo')
            ->setDescription('A full or partial URI. Supports regular expressions.');
        $fields->addFieldToTab('Root.Main', DropdownField::create('DataType', 'Data Type', $dataObjects));
        $mimes = TextareaField::create('MimeTypes', 'Mime-types')
            ->setRows(3)
            ->setDescription('Be sure to pick a Mime-type that the above Data Type supports'
            . ' e.g. text/html (<strong>SiteTree</strong>),'
            . ' image/png or image/jpeg (<strong>Image</strong>)'
            . ' or application/pdf (<strong>File</strong>), separated by a newline.'
            );
        $fields->addFieldToTab('Root.Main', $mimes);
        $notes = TextareaField::create('Notes', 'Notes')
            ->setDescription('Use this field to add any notes about this schema.'
            . ' (Purely informational. Data is not used in imports).');
        $fields->addFieldToTab('Root.Main', $notes);

        $importRules = $fields->dataFieldByName('ImportRules');
        $fields->removeFieldFromTab('Root', 'ImportRules');

        // Don't show for File subclasses, these onbviously don't use CSS import rules
        if ($this->DataType && in_array(File::class, ClassInfo::ancestry($this->DataType))) {
            return $fields;
        }

        if ($importRules) {
            $conf = $importRules->getConfig();
            $conf->removeComponentsByType([
                GridFieldAddExistingAutocompleter::class,
                GridFieldAddNewButton::class
            ]);
            $addNewButton = new GridFieldAddNewButton('after');
            $addNewButton->setButtonName("Add Rule");
            $conf->addComponent($addNewButton);
            $fields->addFieldToTab('Root.Main', $importRules);
        }

        return $fields;
    }

    /**
     *
     * @return void
     */
    public function requireDefaultRecords()
    {
        foreach (StaticSiteContentSource::get() as $source) {
            if (!$source->Schemas()->count()) {
                Debug::message("Making a schema for $source->ID");
                $defaultSchema = StaticSiteContentSourceImportSchema::create();
                $defaultSchema->Order = 1000000;
                $defaultSchema->AppliesTo = self::$default_applies_to;
                $defaultSchema->DataType = Page::class;
                $defaultSchema->ContentSourceID = $source->ID;
                $defaultSchema->MimeTypes = "text/html";
                $defaultSchema->write();

                foreach (StaticSiteContentSourceImportRule::get()->filter(array('SchemaID' => 0)) as $rule) {
                    $rule->SchemaID = $defaultSchema->ID;
                    $rule->write();
                }
            }
        }
    }

    /**
     * Return the import rules in a format suitable for configuring StaticSiteContentExtractor.
     *
     * @return array $output. A map of field name => [CSS selector, CSS selector, ...]
     */
    public function getImportRules(): array
    {
        $output = [];

        foreach ($this->ImportRules() as $rule) {
            if (!isset($output[$rule->FieldName])) {
                $output[$rule->FieldName] = [];
            }

            $ruleArray = [
                'selector' => $rule->CSSSelector,
                'attribute' => $rule->Attribute,
                'plaintext' => $rule->PlainText,
                'excludeselectors' => preg_split("#[\r\n\s,]+#", trim($rule->ExcludeCSSSelector)),
                'outerhtml' => $rule->OuterHTML,
            ];

            $output[$rule->FieldName][] = $ruleArray;
        }

        return $output;
    }

    /**
     *
     * @return \ValidationResult
     */
    public function validate()
    {
        $result = ValidationResult::create();
        $mime = $this->validateMimes();
        $appliesTo = $this->validateUrlPattern();

        if (!is_bool($mime)) {
            $result->addError('Invalid Mime-type "' . $mime . '" for DataType "' . $this->DataType . '"');
        }

        if (!is_bool($appliesTo)) {
            $result->addError('Invalid PCRE expression "' . $appliesTo . '"');
        }

        return $result;
    }

    /**
     *
     * Validate user-inputted mime-types until we use some sort of multi-select list in the CMS to select from (@todo).
     *
     * @return mixed boolean|string Boolean true if all is OK, otherwise the invalid mimeType to be shown in the CMS UI
     */
    public function validateMimes()
    {
        $selectedMimes = StaticSiteMimeProcessor::get_mimetypes_from_text($this->MimeTypes);

        $dt = $this->DataType ?? $_POST['DataType']; // @todo
        if (!$dt) {
            return true; // probably just creating
        }

        /*
         * This is v.sketch. It relies on the name of user-entered DataTypes containing
         * the string we want to match on in its classname = bad
         * @todo prolly just replace this wih a regex..
         */
        switch ($dt) {
            case stristr($dt, 'image') !== false:
                $type = 'image';
                break;
            case stristr($dt, 'file') !== false:
                $type = 'file';
                break;
            case stristr($dt, 'page') !== false:
            default:
                $type = 'sitetree';
                break;
        }

        $mimesForSSType = StaticSiteMimeProcessor::get_mime_for_ss_type($type);
        $mimes = $mimesForSSType ? $mimesForSSType : [];

        foreach ($mimes as $mime) {
            if (!in_array($mime, $mimesForSSType)) {
                return $mime;
            }
        }

        return true;
    }

    /**
     *
     * Prevent ugly CMS console errors if user-defined regex's are not 100% PCRE compatible.
     *
     * @return mixed string | boolean
     */
    public function validateUrlPattern()
    {
        // Basic check uses negative lookbehind and checks if glob chars exist which are _not_ preceeded by a '.' char
        if (preg_match("#(?<!.)(\+|\*)#", $this->AppliesTo)) {
            return $this->AppliesTo;
        }
        return true;
    }
}
