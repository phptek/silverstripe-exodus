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
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDatetime;

// We do this or PHP8+ complains about the ageing phpcrawl lib
ini_set('error_reporting', 'E_ALL & ~E_DEPRECATED');

/**
 * Define the overarching content-sources, schemas etc. Probably better named a "Migration Profile".
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
    public const CACHE_DIR_PREFIX = 'static-site-0'; // Default (The zero-suffix is used by test-suite)

    /**
     * @var string
     */
    private static $table_name = 'StaticSiteContentSource';

    /**
     * @var config
     */
    private static $singular_name = 'Migration Profile';

    /**
     * @var config
     */
    private static $plural_name = 'Migration Profiles';

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
            'Name'
        ]);

        // Because we can't pass arrays to FieldList::insertBefore
        foreach ([
            HeaderField::create('ProfileHeading', 'Migration Profile Configuration'),
            LiteralField::create('ProfileIntro', ''
                . '<p class="message notice">'
                . 'This where the basics of your migration profile are configured.'
                . '</p>'
            )] as $introField) {
                $fields->insertBefore('BaseUrl', $introField);
        }

        // Processing Options
        $processingOptions = ['' => "No Processing"];

        foreach (ClassInfo::implementorsOf(StaticSiteUrlProcessor::class) as $processor) {
            $processorObj = singleton($processor);
            $processingOptions[$processor] = $processorObj->getName();
        }

        $fields->addFieldsToTab(
            'Root.Main', [
                TextField::create("BaseUrl", "Base URL")
                    ->setDescription('The base URL of the site to be crawled and imported.'),
                DropdownField::create("UrlProcessor", "URL Transformation", $processingOptions)
                ->setDescription('Select the way in which crawled URLs should be transformed and cleaned-up.'),
                CheckboxField::create("ParseCSS", "Fetch external CSS")
                    ->setDescription("Fetch images defined as CSS <strong>background-image</strong> which are not ordinarily reachable by crawling alone."),
                CheckboxField::create("AutoRunTask", "Automatically rewrite links into Silverstripe-aware links")
                    ->setDescription("This will run a link-rewrite task automatically once an import has completed.")
            ]
        );
        $fields->fieldByName('Root.Main')->setTitle('Profile');
        $fields->insertBefore('BaseUrl', TextField::create('Name', 'Name')
            ->setDescription('Allows you to differentiate between profiles.')
        );

        // Schema Gridfield
        $fields->addFieldToTab('Root.Main', HeaderField::create('ImportConfigHeader', 'Import Schema Configuration'));
        $addNewButton = (new GridFieldAddNewButton('before'))->setButtonName("Add Schema");
        $importRules = $fields->dataFieldByName('Schemas');
        $importRules->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
        $importRules->getConfig()->addComponent($addNewButton);
        $fields->removeFieldFromTab("Root", "Schemas");
        $fields->addFieldToTab('Root.Main', LiteralField::create(
            'SchemaIntro',
            ''
            . '<p class="message notice">Schema map MIME-Types to Silverstripe content classes and'
            . ' are related to one or more Import Rules. Each rule determines how content located at crawled URLs'
            . ' should be imported into a content classes\' fields with the use of CSS selectors.'
            . ' Where more than one schema exists for a field, they\'ll be processed in the order of Priority:'
            . ' The first Schema to match a URI Pattern will be the one used for that field.</p>'
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
        $crawlMsg = '';

        // Disable crawl-button if assets dir isn't writable
        // TODO this will need to change if change the default location of crawl data. Like _why_ is it in assets?
        if (!file_exists(ASSETS_PATH) || !is_writable(ASSETS_PATH)) {
            $crawlMsg = '<p class="message warning">Warning: Assets directory is not writable.</p>';
            $crawlButton->setDisabled(true);
        }

        $fields->addFieldsToTab('Root.Crawl', [
            ReadonlyField::create("CrawlStatus", "Crawl Status", $this->urlList()->getSpiderStatus()),
            ReadonlyField::create("NumURIs", "Number of URIs Crawled", $this->urlList()->getNumURIs()),
            LiteralField::create(
                'CrawlActions',
                $crawlMsg ? '<p class="message notice">' . $crawlMsg . '</p>' : ''
                . '<div class="btn-toolbar">' . $crawlButton->forTemplate() . '</div>'
            )
        ]);

        // Because we can't pass arrays to FieldList::insertBefore
        foreach ([
            HeaderField::create('CrawlHeading', 'Source Site Crawling'),
            LiteralField::create('CrawlIntro', ''
                . '<p class="message notice">'
                . 'Before you can load any content into Silverstripe, all source URLs must first be crawled.'
                . ' Select the button below to start or resume a crawl as applicable.'
                . '</p>'
            )] as $introField) {
                $fields->insertBefore('CrawlStatus', $introField);
        }

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
            ->setTitle('Additional URIs');
        $fields->dataFieldByName("UrlExcludePatterns")
            ->setDescription("URLs that should be excluded. (Supports regular expressions e.g. '/about/.*'). One per line")
            ->setTitle('Excluded URLs');

        $hasImports = DataObject::get(StaticSiteImportDataObject::class);
        $_source = [];

        foreach ($hasImports as $import) {
            $date = DBField::create_field(DBDatetime::class, $import->Created)->Time24();
            $_source[$import->ID] = $date . ' (Import #' . $import->ID . ')';
        }

        $fields->addFieldsToTab('Root.Import', [
            HeaderField::create('ImportHeading', 'Source Site Import'),
            LiteralField::create('ImportIntro', ''
                . '<p class="message notice">'
                . 'Use this area to configure where in the current IA imported page content should appear.'
                . ' The same goes for imported files and images.'
                . '</p>'
        )]);

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

        $fields->addFieldsToTab('Root.Environment', [
            HeaderField::create('EnvHeading', 'Webserver Environment'),
            LiteralField::create('EnvIntro', ''
                . '<p class="message notice">'
                . 'Refer to this area for information related to the PHP and Webserver environment'
                . ' which may affect the proper function and performance of this tool.'
                . '</p>'),
            LiteralField::create('EnvInfo', ''
                . '<ul>'
                . '<li>PHP Info: ' . $_SERVER['PHP_VERSION'] . '</li>'
                . '<li>Webserver Info: ' . $_SERVER['SERVER_SOFTWARE'] . '</li>'
                . '<li>max_execution_time: ' . sprintf('%s seconds', ini_get('max_execution_time')) . '</li>'
                . '<li>memory_limit: ' . sprintf('%d Mb', ini_get('memory_limit')) . '</li>'
                . '</ul>'
            )
        ]);

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
        // Ensure the "Order" (Priority) setting is respected
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
