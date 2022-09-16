<?php

namespace PhpTek\Exodus\Model;

use ExternalContentSource;
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
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Forms\TextField;
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
    public const CACHE_DIR_PREFIX = 'static-site-0'; // Default (zero-suffix is used by tests)

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
    public $cacheDir = null;

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
        $this->cacheDir = preg_replace('#[0-9]+$#', $this->ID, self::CACHE_DIR_PREFIX);
        $this->utils = singleton(StaticSiteUtils::class);
    }

    /**
     * Template method used to display the results of a successful crawl into the central
     * column of the CMS.
     *
     * @return string
     */
    public function listofCrawledItems(): string
    {
        $list = $this->urlList();
        $ulist = '';

        if ($list->getSpiderStatus() !== StaticSiteUrlList::CRAWL_STATUS_COMPLETE) {
            return '';
        }

        foreach (array_unique($list->getProcessedURLs()) as $raw => $processed) {
            if ($raw != $processed) {
                $ulist .= '<li>' . sprintf('%s (was: %s)', $processed, $raw) . '</li>';
            } else {
                $ulist .= '<li>' . $processed . '</li>';
            }
        }

        return '<ul>' . $ulist . '</ul>';
    }

    /**
     *
     * @return FieldList
     * @throws LogicException
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeFieldsFromTab('Root', [
            'Pages',
            'Files',
            'ShowContentInMenu',
            'Name']
        );
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

        $fields->addFieldsToTab(
            'Root.Main', [
                TextField::create("BaseUrl", "Base URL")
                    ->setDescription('The main URL of the site you wish to import e.g. "https://foo.com".'),
                OptionsetField::create("UrlProcessor", "URL Processing", $processingOptions),
                CheckboxField::create("ParseCSS", "Fetch external CSS")
                    ->setDescription("Fetch images defined as CSS <strong>background-image</strong> selectors which are not ordinarily reachable."),
                CheckboxField::create("AutoRunTask", "Automatically rewrite links into Silverstripe-aware links")
                    ->setDescription("This will run the built-in link-rewriter task automatically once an import has completed.")
            ]
        );
        $fields->insertBefore('BaseUrl', TextField::create('Name', 'Name')
            ->setDescription('Allows you to differentiate between imports.')
        );

        // Schema Gridfield
        $fields->addFieldToTab('Root.Main', HeaderField::create('ImportConfigHeader', 'Import Schema Configuration'));
        $addNewButton = new GridFieldAddNewButton('after');
        $addNewButton->setButtonName("Add Schema");
        $importRules = $fields->dataFieldByName('Schemas');
        $importRules->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
        $importRules->getConfig()->addComponent($addNewButton);
        $fields->removeFieldFromTab("Root", "Schemas");
        $fields->addFieldToTab('Root.Main', LiteralField::create(
            'SchemaIntro',
            ''
            . '<p class="message notice">Schemas define'
            . ' rules for importing crawled content into database fields'
            . ' with the use of CSS selectors. If more than one schema exists for a field, then they will be'
            . ' processed in the order of Priority. The first Schema to a match a URI Pattern will be'
            . ' the one used for that field.</p>'
        ));
        $fields->addFieldToTab("Root.Main", $importRules);

        switch ($this->urlList()->getSpiderStatus()) {
            case StaticSiteUrlList::CRAWL_STATUS_NOTSTARTED:
                $crawlButtonText = _t('StaticSiteContentSource.CRAWL_SITE', 'Crawl');
                break;
            case StaticSiteUrlList::CRAWL_STATUS_PARTIAL:
                $crawlButtonText = _t('StaticSiteContentSource.RESUME_CRAWLING', 'Resume Crawl');
                break;
            case StaticSiteUrlList::CRAWL_STATUS_COMPLETE:
                $crawlButtonText = _t('StaticSiteContentSource.RECRAWL_SITE', 'Re-Crawl');
                break;
            default:
                throw new \LogicException("Invalid getSpiderStatus() value '".$this->urlList()->getSpiderStatus().";");
        }

        $crawlButton = FormAction::create('crawlsite', $crawlButtonText)
            ->setAttribute('data-icon', 'arrow-circle-double')
            ->setUseButtonTag(true)
            ->addExtraClass('btn action btn btn-primary tool-button font-icon-plus');
        $crawlMsg = 'Select the button below to start or resume a crawl.';

        // Disable crawl-button if assets dir isn't writable
        if (!file_exists(ASSETS_PATH) || !is_writable(ASSETS_PATH)) {
            $crawlMsg = '<p class="message warning">Warning: Assets directory is not writable.</p>';
            $crawlButton->setDisabled(true);
        }

        $fields->addFieldsToTab('Root.Crawl', [
            ReadonlyField::create("CrawlStatus", "Crawl Status", $this->urlList()->getSpiderStatus()),
            ReadonlyField::create("NumURIs", "Number of URIs Crawled", $this->urlList()->getNumURIs()),
            LiteralField::create(
                'CrawlActions',
                '<p class="message notice">Before you can load any content into Silverstripe, all source URLs must first be crawled. '
                . $crawlMsg . '</p>'
                . '<div class="btn-toolbar">' . $crawlButton->forTemplate() . '</div>'
            )
        ]);

        /*
         * @todo use customise() and arrange this using an includes .ss template fragment
         */
        if ($this->urlList()->getSpiderStatus() == StaticSiteUrlList::CRAWL_STATUS_COMPLETE) {
            $fields->addFieldToTab(
                'Root.Crawl',
                LiteralField::create(
                    'CrawlURLListUIntro',
                    '<p class="mesage notice">Review the list of crawled URIs below. When you\'re happy with the import'
                    . ' you can proceed to the "Import" tab and follow the instructions there.</p>'
                ),
                LiteralField::create('CrawlURLList', $this->listofCrawledItems())
            );
        }

        $fields->dataFieldByName("ExtraCrawlUrls")
            ->setDescription("Add URIs that are not reachable via links when content scraping, eg: '/about/team'. One per line")
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
                ->addExtraClass('btn action btn btn-primary tool-button font-icon-plus')
                ->setUseButtonTag(true);

            $clearImportField = ToggleCompositeField::create('ClearImports', 'Clear Import Metadata', [
                LiteralField::create('ImportCountText', '<p>Each time an import is run, some meta information is stored such as an import identifier and failed-link records.<br/><br/></p>'),
                LiteralField::create('ImportCount', '<p>Total imports: ' . $importCount . '</p>'),
                ListboxField::create('ShowImports', 'Select import(s) to clear:', $_source, '', null, true),
                CheckboxField::create('ClearAllImports', 'Clear all import meta-data', 0),
                LiteralField::create('ImportActions', '<div class="btn-toolbar">' . $clearImportButton->forTemplate() . '</div>')
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
            $this->urlList = StaticSiteUrlList::create($this, ASSETS_PATH . "/{$this->cacheDir}");

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
            throw new \LogicException('Can\'t crawl a site until the "Base URL" field is set.');
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
            array_push($schemaMimeTypes, StaticSiteUrlList::config()->get('undefined_mime_type'));

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
            $appliesTo = $schema::config()->get('default_applies_to');
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
