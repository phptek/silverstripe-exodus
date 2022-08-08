<?php

namespace PhpTek\Exodus\Model;

use \ExternalContentSource;
use PhpTek\Exodus\Transform\StaticSiteImporter;
use PhpTek\Exodus\Tool\StaticSiteUtils;
use PhpTek\Exodus\Tool\StaticSiteUrlList;
use PhpTek\Exodus\Tool\StaticSiteMimeProcessor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Injector\Injectable;
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
        "Schemas" => StaticSiteContentSource_ImportSchema::class,
        "Pages" => SiteTree::class,
        "Files" => File::class,
    ];

    /**
     *
     * @var array
     */
    private static $export_columns = [
        "StaticSiteContentSource_ImportSchema.DataType",
        "StaticSiteContentSource_ImportSchema.Order",
        "StaticSiteContentSource_ImportSchema.AppliesTo",
        "StaticSiteContentSource_ImportSchema.MimeTypes",
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

        $fields->insertBefore(HeaderField::create('CrawlConfigHeader', 'Crawl config'), 'BaseUrl');

        // Processing Option
        $processingOptions = ['' => "No pre-processing"];
        foreach (ClassInfo::implementorsOf('StaticSiteUrlProcessor') as $processor) {
            $processorObj = new $processor();
            $processingOptions[$processor] = "<strong>" . Convert::raw2xml($processorObj->getName())
                . "</strong><br>" . Convert::raw2xml($processorObj->getDescription());
        }
        $fields->addFieldToTab("Root.Main", new OptionsetField("UrlProcessor", "URL processing", $processingOptions));
        $fields->addFieldToTab("Root.Main", $parseCss = new CheckboxField("ParseCSS", "Fetch external CSS"));
        $parseCss->setDescription("Fetches images defined in CSS <strong>background-image</strong> selectors, not ordinarily reachable.");
        $fields->addFieldToTab("Root.Main", $autoRunLinkTask = new CheckboxField("AutoRunTask", "Automatically run link-rewrite task"));
        $autoRunLinkTask->setDescription("This will run the link-rewriter task automatically once an import has completed.");

        // Schemas Gridfield
        $fields->addFieldToTab('Root.Main', new HeaderField('ImportConfigHeader', 'Import config'));
        $importRules = $fields->dataFieldByName('Schemas');
        $importRules->getConfig()->removeComponentsByType(GridFieldAddExistingAutocompleter::class);
        $importRules->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
        $addNewButton = new GridFieldAddNewButton('after');
        $addNewButton->setButtonName("Add Schema");
        $importRules->getConfig()->addComponent($addNewButton);
        $fields->removeFieldFromTab("Root", "Schemas");
        $fields->addFieldToTab("Root.Main", new LiteralField("", "<p>Schemas define rules for importing scraped content into database fields"
            . " via CSS selector rules. If more than one schema exists for a field, then they will be"
            . " processed in the order of Priority. The first Schema to a match a URL Pattern will be the one used for that field.</p>"));
        $fields->addFieldToTab("Root.Main", $importRules);

        switch ($this->urlList()->getSpiderStatus()) {
            case "Not started":
                $crawlButtonText = _t('StaticSiteContentSource.CRAWL_SITE', 'Crawl site');
                break;
            case "Partial":
                $crawlButtonText = _t('StaticSiteContentSource.RESUME_CRAWLING', 'Resume crawling');
                break;
            case "Complete":
                $crawlButtonText = _t('StaticSiteContentSource.RECRAWL_SITE', 'Re-crawl site');
                break;
            default:
                throw new \LogicException("Invalid getSpiderStatus() value '".$this->urlList()->getSpiderStatus().";");
        }

        $crawlButton = FormAction::create('crawlsite', $crawlButtonText)
            ->setAttribute('data-icon', 'arrow-circle-double')
            ->setUseButtonTag(true);

        // Disable crawl-button if assets dir isn't writable
        $crawlMsg = 'Click the button below to do so:';
        if (!file_exists(ASSETS_PATH) || !is_writable(ASSETS_PATH)) {
            $crawlMsg = '<span class="message warning"><strong>Warning!</strong> Assets directory is not writable.</span>';
            $crawlButton->setDisabled(true);
        }

        $fields->addFieldsToTab('Root.Crawl', [
            new ReadonlyField("CrawlStatus", "Crawling Status", $this->urlList()->getSpiderStatus()),
            new ReadonlyField("NumURLs", "Number of URLs", $this->urlList()->getNumURLs()),
            new LiteralField(
                'CrawlActions',
                "<p>Before importing this content, all URLs on the site must be crawled (like a search engine does)."
                . " $crawlMsg</p>"
                . "<div class='Actions'>{$crawlButton->forTemplate()}</div>"
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
                new LiteralField('CrawlURLList', "<p>The following URLs have been identified:</p>" . $urlsAsUL)
            );
        }

        $fields->dataFieldByName("ExtraCrawlUrls")
            ->setDescription("Add URLs that are not reachable through content scraping, eg: '/about/team'. One per line")
            ->setTitle('Additional URLs');
        $fields->dataFieldByName("UrlExcludePatterns")
            ->setDescription("URLs that should be excluded. (Supports regular expressions e.g. '/about/.*'). One per URL")
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
                LiteralField::create('ImportActions', "<div class='Actions'>{$clearImportButton->forTemplate()}</div>")
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
                $urlList->setUrlProcessor(new $processorClass());
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
            $this->urlList = new StaticSiteUrlList($this, "../assets/{$this->staticSiteCacheDir}");
            if ($processorClass = $this->UrlProcessor) {
                $this->urlList->setUrlProcessor(new $processorClass());
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
    public function crawl($limit=false, $verbose=false)
    {
        if (!$this->BaseUrl) {
            throw new \LogicException("Can't crawl a site until Base URL is set.");
        }
        return $this->urlList()->crawl($limit, $verbose);
    }

    /**
     * Fetch an appropriate schema for a given URL and/or Mime-Type.
     * If no matches are found, boolean false is returned.
     *
     * @param string $absoluteURL
     * @param string $mimeType (Optional)
     * @return mixed StaticSiteContentSource_ImportSchema $schema or boolean false if no schema matches are found
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
     * @param StaticSiteContentSource_ImportSchema $schema
     * @param string $url
     * @return boolean
     */
    public function schemaCanParseURL(StaticSiteContentSource_ImportSchema $schema, $url)
    {
        $appliesTo = $schema->AppliesTo;
        if (!strlen($appliesTo)) {
            $appliesTo = $schema::$default_applies_to;
        }

        // Use (escaped) pipes for delimeters as pipes are unlikely to appear in legitimate URLs
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

        return new StaticSiteContentItem($this, $id);
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
        return new StaticSiteImporter();
    }

    /**
     *
     * @return boolean
     */
    public function isValid()
    {
        if (!(bool)$this->BaseUrl) {
            return false;
        }
        return true;
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canImport($member = NULL, $context = [])
    {
        return $this->isValid();
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canCreate($member = NULL, $context = [])
    {
        return true;
    }
}

/**
 * A collection of ImportRules that apply to some or all of the content being imported.
 */
class StaticSiteContentSource_ImportSchema extends DataObject
{
    use Injectable;

    /**
     * Default
     *
     * @var string
     */
    private static $default_applies_to = '.*';

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
        "DataType" => "Data type",
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
        "ImportRules" => StaticSiteContentSource_ImportRule::class,
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
        $dataObjects = ClassInfo::subclassesFor('DataObject');
        array_shift($dataObjects);
        natcasesort($dataObjects);
        $appliesTo = $fields->dataFieldByName('AppliesTo');
        $appliesTo->setDescription('A full or partial URI. Supports regular expressions.');
        $fields->addFieldToTab('Root.Main', new DropdownField('DataType', 'DataType', $dataObjects));
        $mimes = new TextareaField('MimeTypes', 'Mime-types');
        $mimes->setRows(3);
        $mimes->setDescription('Be sure to pick a Mime-type that the DataType supports. e.g. text/html (<strong>SiteTree</strong>), image/png or image/jpeg (<strong>Image</strong>) or application/pdf (<strong>File</strong>), separated by a newline.');
        $fields->addFieldToTab('Root.Main', $mimes);
        $notes = new TextareaField('Notes', 'Notes');
        $notes->setDescription('Use this field to add any notes about this schema. (Purely informational. Data is not used in imports)');
        $fields->addFieldToTab('Root.Main', $notes);

        $importRules = $fields->dataFieldByName('ImportRules');
        $fields->removeFieldFromTab('Root', 'ImportRules');

        // Exclude File, as it doesn't use import rules
        if ($this->DataType && in_array('File', ClassInfo::ancestry($this->DataType))) {
            return $fields;
        }

        if ($importRules) {
            $importRules->getConfig()->removeComponentsByType('GridFieldAddExistingAutocompleter');
            $importRules->getConfig()->removeComponentsByType('GridFieldAddNewButton');
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
                $defaultSchema = new StaticSiteContentSource_ImportSchema();
                $defaultSchema->Order = 1000000;
                $defaultSchema->AppliesTo = self::$default_applies_to;
                $defaultSchema->DataType = "Page";
                $defaultSchema->ContentSourceID = $source->ID;
                $defaultSchema->MimeTypes = "text/html";
                $defaultSchema->write();

                foreach (StaticSiteContentSource_ImportRule::get()->filter(array('SchemaID' => 0)) as $rule) {
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
        $result = new ValidationResult();
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
class StaticSiteContentSource_ImportRule extends DataObject
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
        "FieldName" => "Field Name",
        "CSSSelector" => "CSS Selector",
        "Attribute" => "Element attribute",
        "PlainText" => "Convert to plain text",
        "OuterHTML" => "Use the outer HTML",
    ];

    /**
     *
     * @var array
     */
    private static $has_one = [
        "Schema" => StaticSiteContentSource_ImportSchema::class,
    ];

    /**
     *
     * @return string
     */
    public function getTitle()
    {
        return ($this->FieldName) ? $this->FieldName : $this->ID;
    }

    /**
     *
     * @return string
     */
    public function getAbsoluteURL()
    {
        return ($this->URLSegment) ? $this->URLSegment : $this->Filename;
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
            $fieldList = singleton($dataType)->inheritedDatabaseFields();
            $fieldList = array_combine(array_keys($fieldList), array_keys($fieldList));
            unset($fieldList->ParentID);
            unset($fieldList->WorkflowDefinitionID);
            unset($fieldList->Version);

            $fieldNameField = new DropdownField("FieldName", "Field Name", $fieldList);
            $fieldNameField->setEmptyString("(choose)");
            $fields->insertBefore($fieldNameField, "CSSSelector");
        } else {
            $fields->replaceField('FieldName', $fieldName = new ReadonlyField("FieldName", "Field Name"));
            $fieldName->setDescription('Save this rule before being able to add a field name');
        }

        return $fields;
    }
}
