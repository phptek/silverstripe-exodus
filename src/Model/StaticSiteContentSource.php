<?php

namespace PhpTek\Exodus\Model;

use ExternalContentSource;
use Page;
use PhpTek\Exodus\Transform\StaticSiteImporter;
use PhpTek\Exodus\Tool\StaticSiteUtils;
use PhpTek\Exodus\Tool\StaticSiteUrlList;
use PhpTek\Exodus\Tool\StaticSiteMimeProcessor;
use PhpTek\Exodus\Tool\StaticSiteUrlProcessor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldAddExistingAutocompleter;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Dev\Debug;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Define the overarching content-sources, schemas etc.
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteContentSource extends ExternalContentSource
{

    /**
     * @var string
     */
    private static $table_name = 'StaticSiteContentSource';

    /**
     *
     * @var array
     */
    private static $db = [
        'BaseUrl' => DBVarchar::class,
        'UrlProcessor' => DBVarchar::class,
        'ExtraCrawlUrls' => DBText::class,
        'UrlExcludePatterns' => DBText::class,
        'ParseCSS' => DBBoolean::class,
        'AutoRunTask' => DBBoolean::class,
    ];

    /**
     *
     * @var array
     */
    private static $has_many = [
        "Schemas" => StaticSiteContentSourceImportSchema::class,
        "Pages" => SiteTree::class,
        "Files" => File::class,
    ];

    /**
     *
     * @var array
     */
    private static $export_columns = [
        "StaticSiteContentSourceImportSchema.DataType",
        "StaticSiteContentSourceImportSchema.Order",
        "StaticSiteContentSourceImportSchema.AppliesTo",
        "StaticSiteContentSourceImportSchema.MimeTypes",
    ];

    /**
     *
     * @var string
     */
    public $absoluteURL = null;

    /**
     * Where do we store our items for caching?
     * Also used by calling logic
     *
     * @var string
     */
    public $staticSiteCacheDir = null;

    /**
     * Holds the StaticSiteUtils object on construct
     *
     * @var StaticSiteUtils $utils
     */
    protected $utils;

    /**
     *
     * @param array|null $record This will be null for a new database record.
     * @param bool $isSingleton
     * @param DataModel $model
     * @return void
     */
    public function __construct($record = null, $isSingleton = false, $model = null)
    {
        parent::__construct($record, $isSingleton, $model);
        $this->staticSiteCacheDir = "static-site-{$this->ID}";
        $this->utils = singleton(StaticSiteUtils::class);
    }

    /**
     *
     * @return FieldList
     * @throws LogicException
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeFieldFromTab("Root", "Pages");
        $fields->removeFieldFromTab("Root", "Files");
        $fields->removeFieldFromTab("Root", "ShowContentInMenu");
        $fields->insertBefore(HeaderField::create('CrawlConfigHeader', 'Import Configuration'), 'BaseUrl');

        // Processing Option
        $processingOptions = ['' => "No pre-processing"];

        foreach (ClassInfo::implementorsOf(StaticSiteUrlProcessor::class) as $processor) {
            $processorObj = singleton($processor);
            $processingOptions[$processor] = sprintf(
                '%s: %s',
                $processorObj->getName(),
                $processorObj->getDescription()
            );
        }

        $fields->addFieldToTab("Root.Main", TextField::create("BaseUrl", "Base URL"), 'ExtraCrawlUrls');
        $fields->addFieldToTab("Root.Main", OptionsetField::create("UrlProcessor", "URL Processing", $processingOptions));
        $fields->addFieldToTab("Root.Main", $parseCss = CheckboxField::create("ParseCSS", "Fetch external CSS"));
        $parseCss->setDescription("Fetch images defined as CSS <strong>background-image</strong> selectors which are not ordinarily reachable.");
        $fields->addFieldToTab("Root.Main", $autoRunLinkTask = CheckboxField::create("AutoRunTask", "Automatically run link-rewrite task"));
        $autoRunLinkTask->setDescription("This will run the built-in link-rewriter task automatically once an import has completed.");

        // Schema Gridfield
        $fields->addFieldToTab('Root.Main', HeaderField::create('ImportConfigHeader', 'Import Configuration'));
        $addNewButton = new GridFieldAddNewButton('after');
        $addNewButton->setButtonName("Add Schema");
        $importRules = $fields->dataFieldByName('Schemas');
        $importRules->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
        $importRules->getConfig()->addComponent($addNewButton);
        $fields->removeFieldFromTab("Root", "Schemas");
        $fields->addFieldToTab('Root.Main', LiteralField::create('SchemaIntro', ''
            . '<p class="message notice">Schemas define'
            . ' rules for importing crawled content into database fields'
            . ' with the use of CSS selectors. If more than one schema exists for a field, then they will be'
            . ' processed in the order of Priority. The first Schema to a match a URI Pattern will be'
            . ' the one used for that field.</p>'
        ));
        $fields->addFieldToTab("Root.Main", $importRules);

        switch ($this->urlList()->getSpiderStatus()) {
            case "Not started":
                $crawlButtonText = _t('StaticSiteContentSource.CRAWL_SITE', 'Crawl');
                break;
            case "Partial":
                $crawlButtonText = _t('StaticSiteContentSource.RESUME_CRAWLING', 'Resume Crawl');
                break;
            case "Complete":
                $crawlButtonText = _t('StaticSiteContentSource.RECRAWL_SITE', 'Re-Crawl');
                break;
            default:
                throw new \LogicException("Invalid getSpiderStatus() value '".$this->urlList()->getSpiderStatus().";");
        }

        $crawlButton = FormAction::create('crawlsite', $crawlButtonText)
            ->setAttribute('data-icon', 'arrow-circle-double')
            ->setUseButtonTag(true)
            ->addExtraClass('btn action btn btn-primary tool-button font-icon-plus');
        $crawlMsg = 'Click the button below to do so:';

        // Disable crawl-button if assets dir isn't writable
        if (!file_exists(ASSETS_PATH) || !is_writable(ASSETS_PATH)) {
            $crawlMsg = '<p class="message warning">Warning: Assets directory is not writable.</p>';
            $crawlButton->setDisabled(true);
        }

        $fields->addFieldsToTab('Root.Crawl', [
            ReadonlyField::create("CrawlStatus", "Crawling Status", $this->urlList()->getSpiderStatus()),
            ReadonlyField::create("NumURLs", "Number of URLs", $this->urlList()->getNumURLs()),
            LiteralField::create(
                'CrawlActions',
                "<p>Before importing this content, all URLs on the site must be crawled (like a search engine does)."
                . " $crawlMsg</p>"
                . '<div class="btn-toolbar">' . $crawlButton->forTemplate() . '</div>'
            )
        ]);

        /*
         * @todo use customise() and arrange this using an includes .ss template fragment
         */
        if ($this->urlList()->getSpiderStatus() == "Complete") {
            $urlsAsUL = "<ul>";
            $processedUrls = $this->urlList()->getProcessedURLs();
            $processed = ($processedUrls ? $processedUrls : []);
            $list = array_unique($processed);

            foreach ($list as $raw => $processed) {
                if ($raw == $processed) {
                    $urlsAsUL .= "<li>$processed</li>";
                } else {
                    $urlsAsUL .= "<li>$processed <em>(was: $raw)</em></li>";
                }
            }
            $urlsAsUL .= "</ul>";

            $fields->addFieldToTab(
                'Root.Crawl',
                LiteralField::create('CrawlURLList', '<p class="mesage notice">The following URLs have been identified: </p>' . $urlsAsUL)
            );
        }

        $fields->dataFieldByName("ExtraCrawlUrls")
            ->setDescription("Add URLs that are not reachable through content scraping, eg: '/about/team'. One per line")
            ->setTitle('Additional URLs');
        $fields->dataFieldByName("UrlExcludePatterns")
            ->setDescription("URLs that should be excluded. (Supports regular expressions e.g. '/about/.*'). One per line")
            ->setTitle('Excluded URLs');

        $hasImports = DataObject::get(StaticSiteImportDataObject::class);
        $_source = [];
        foreach ($hasImports as $import) {
            $date = DBField::create_field(DBDatetime::class, $import->Created)->Time24();
            $_source[$import->ID] = $date . ' (Import #' . $import->ID . ')';
        }

        if ($importCount = $hasImports->count()) {
            $clearImportButton = FormAction::create('clearimports', 'Clear selected imports')
                ->setAttribute('data-icon', 'arrow-circle-double')
                ->setUseButtonTag(true);

            $clearImportField = ToggleCompositeField::create('ClearImports', 'Clear import meta-data', [
                LiteralField::create('ImportCountText', '<p>Each time an import is run, some meta information is stored such as an import identifier and failed-link records.<br/><br/></p>'),
                LiteralField::create('ImportCount', '<p><strong>Total imports: </strong><span>' . $importCount . '</span></p>'),
                ListboxField::create('ShowImports', 'Select import(s) to clear:', $_source, '', null, true),
                CheckboxField::create('ClearAllImports', 'Clear all import meta-data', 0),
                LiteralField::create('ImportActions', $clearImportButton->forTemplate())
            ])->addExtraClass('clear-imports');
            $fields->addFieldToTab('Root.Import', $clearImportField);
        }

        return $fields;
    }

    /**
     * If the site has been crawled and then subsequently the URLProcessor was changed, we need to ensure
     * URLs are re-processed using the newly selected URL Preprocessor
     *
     * @return void
     */
    public function onAfterWrite()
    {
        parent::onAfterWrite();

        $urlList = $this->urlList();
        if ($this->isChanged('UrlProcessor') && $urlList->hasCrawled()) {
            if ($processorClass = $this->UrlProcessor) {
                $urlList->setUrlProcessor($processorClass::create());
            } else {
                $urlList->setUrlProcessor(null);
            }

            $urlList->reprocessUrls();
        }
    }

    /**
     *
     * @return StaticSiteUrlList
     */
    public function urlList()
    {
        if (!$this->urlList) {
            $this->urlList = StaticSiteUrlList::create($this, ASSETS_DIR . "/{$this->staticSiteCacheDir}");

            if ($processorClass = $this->UrlProcessor) {
                $this->urlList->setUrlProcessor($processorClass::create());
            }
            if ($this->ExtraCrawlUrls) {
                $extraCrawlUrls = preg_split('/\s+/', trim($this->ExtraCrawlUrls));
                $this->urlList->setExtraCrawlUrls($extraCrawlUrls);
            }
            if ($this->UrlExcludePatterns) {
                $urlExcludePatterns = preg_split('/\s+/', trim($this->UrlExcludePatterns));
                $this->urlList->setExcludePatterns($urlExcludePatterns);
            }
        }

        return $this->urlList;
    }

    /**
     * Crawl the target site
     *
     * @param boolean $limit
     * @param boolean $verbose
     * @return StaticSiteCrawler
     * @throws LogicException
     */
    public function crawl($limit = false, $verbose = false)
    {
        if (!$this->BaseUrl) {
            throw new \LogicException('Can\'t crawl a site until "Base URL" is set.');
        }

        return $this->urlList()->crawl($limit, $verbose);
    }

    /**
     * Fetch an appropriate schema for a given URL and/or Mime-Type.
     * If no matches are found, boolean false is returned.
     *
     * @param string $absoluteURL
     * @param string $mimeType (Optional)
     * @return mixed StaticSiteContentSourceImportSchema $schema or boolean false if no schema matches are found
     */
    public function getSchemaForURL($absoluteURL, $mimeType = null)
    {
        $mimeType = StaticSiteMimeProcessor::cleanse($mimeType);
        // Ensure the "Priority" setting is respected
        $schemas = $this->Schemas()->sort('Order');
        foreach ($schemas as $i => $schema) {
            $schemaCanParseURL = $this->schemaCanParseURL($schema, $absoluteURL);
            $schemaMimeTypes = StaticSiteMimeProcessor::get_mimetypes_from_text($schema->MimeTypes);
            $schemaMimeTypesShow = implode(', ', $schemaMimeTypes);
            $this->utils->log(' - Schema: ' . ($i + 1) . ', DataType: ' . $schema->DataType . ', AppliesTo: ' . $schema->AppliesTo . ' mimetypes: ' . $schemaMimeTypesShow);
            array_push($schemaMimeTypes, StaticSiteUrlList::$undefined_mime_type);
            if ($schemaCanParseURL) {
                if ($mimeType && $schemaMimeTypes && (!in_array($mimeType, $schemaMimeTypes))) {
                    continue;
                }
                return $schema;
            }
        }
        return false;
    }

    /**
     * Performs a match on the Schema->AppliedTo field with reference to the URL
     * of the current iteration within getSchemaForURL().
     *
     * @param StaticSiteContentSourceImportSchema $schema
     * @param string $url
     * @return boolean
     */
    public function schemaCanParseURL(StaticSiteContentSourceImportSchema $schema, $url)
    {
        $appliesTo = $schema->AppliesTo;
        if (!strlen($appliesTo)) {
            $appliesTo = $schema::$default_applies_to;
        }

        // Use (escaped) pipes for delimeters as pipes themselves are unlikely to appear in legit URLs
        $appliesTo = str_replace('|', '\|', $appliesTo);
        $urlToTest = str_replace(rtrim($this->BaseUrl, '/'), '', $url);

        if (preg_match("|^$appliesTo|i", $urlToTest)) {
            $this->utils->log(' - ' . __FUNCTION__ . ' matched: ' . $appliesTo . ', Url: ' . $url);
            return true;
        }
        return false;
    }

    /**
     * Returns a StaticSiteContentItem for the given URL
     * Relative URLs are used as the unique identifiers by this importer
     *
     * @param string $id The URL, relative to BaseURL, starting with "/".
     * @return StaticSiteContentItem
     */
    public function getObject($id)
    {
        if ($id[0] != "/") {
            $id = $this->decodeId($id);
            if ($id[0] != "/") {
                throw new \InvalidArgumentException("\$id must start with /");
            }
        }

        return StaticSiteContentItem::create($this, $id);
    }

    /**
     *
     * @return StaticSiteContentItem
     */
    public function getRoot()
    {
        return $this->getObject('/');
    }

    /**
     * Signals external-content module that we wish to operate on `SiteTree` and `File` objects.
     *
     * @return array
     */
    public function allowedImportTargets()
    {
        return [
            'sitetree'	=> true,
            'file' => true,
        ];
    }

    /**
     * Return the root node.
     *
     * @param boolean $showAll
     * @return ArrayList A list containing the root node
     */
    public function stageChildren($showAll = false)
    {
        if (!$this->urlList()->hasCrawled()) {
            return ArrayList::create();
        }

        return ArrayList::create(array(
            $this->getObject("/")
        ));
    }

    /**
     *
     * @param $target
     * @return StaticSiteImporter
     */
    public function getContentImporter($target = null)
    {
        return StaticSiteImporter::create();
    }

    /**
     *
     * @return boolean
     */
    public function isValid()
    {
        return (bool) $this->BaseUrl;
    }

    /**
     *
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canImport($member = null, $context = [])
    {
        return $this->isValid();
    }

    /**
     *
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = [])
    {
        return true;
    }
}

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
    public function getTitle()
    {
        return $this->DataType.' (' .$this->AppliesTo . ')';
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

        $fields->insertBefore('Order', LiteralField::create('ImportIntro', ''
            . '<p class="message notice">An Import Schema maps a source URI regex and Mime-Type'
            . ' to a target content-type (Usually a SiteTree subclass, but could be an ElementalBlock).'
            . ' Content that matches will be saved as the selected Data Type.</p>'
        ));

        $appliesTo = $fields->dataFieldByName('AppliesTo');
        $appliesTo->setDescription('A full or partial URI. Supports regular expressions.');
        $fields->addFieldToTab('Root.Main', DropdownField::create('DataType', 'Data Type', $dataObjects));
        $mimes = TextareaField::create('MimeTypes', 'Mime-types');
        $mimes->setRows(3);
        $mimes->setDescription('Be sure to pick a Mime-type that the above Data Type supports. e.g. text/html (<strong>SiteTree</strong>), image/png or image/jpeg (<strong>Image</strong>) or application/pdf (<strong>File</strong>), separated by a newline.');
        $fields->addFieldToTab('Root.Main', $mimes);
        $notes = TextareaField::create('Notes', 'Notes');
        $notes->setDescription('Use this field to add any notes about this schema. (Purely informational. Data is not used in imports)');
        $fields->addFieldToTab('Root.Main', $notes);

        $importRules = $fields->dataFieldByName('ImportRules');
        $fields->removeFieldFromTab('Root', 'ImportRules');

        // Exclude File, as it doesn't use import rules
        if ($this->DataType && in_array(File::class, ClassInfo::ancestry($this->DataType))) {
            return $fields;
        }

        if ($importRules) {
            $importRules->getConfig()->removeComponentsByType(GridFieldAddExistingAutocompleter::class);
            $importRules->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
            $addNewButton = new GridFieldAddNewButton('after');
            $addNewButton->setButtonName("Add Rule");
            $importRules->getConfig()->addComponent($addNewButton);
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
    public function getImportRules()
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
                'excludeselectors' => preg_split('/\s+/', trim($rule->ExcludeCSSSelector)),
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
        if (!is_bool($mime)) {
            $result->error('Invalid Mime-type "' . $mime . '" for DataType "' . $this->DataType . '"');
        }
        $appliesTo = $this->validateUrlPattern();
        if (!is_bool($appliesTo)) {
            $result->error('Invalid PCRE expression "' . $appliesTo . '"');
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
        $dt = $this->DataType ? $this->DataType : $_POST['DataType']; // @todo
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

        return $fields;
    }
}
