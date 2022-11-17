<?php

namespace PhpTek\Exodus\Model;

use ExternalContentItem;
use ExternalContentTransformer;
use PhpTek\Exodus\Transform\StaticSiteFileTransformer;
use PhpTek\Exodus\Transform\StaticSitePageTransformer;
use PhpTek\Exodus\Tool\StaticSiteMimeProcessor;
use PhpTek\Exodus\Tool\StaticSiteUtils;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\View\Requirements;

/**
 * Deals-to transforming imported SiteTree and File objects
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteContentItem extends ExternalContentItem
{
    /**
     * @var string
     */
    private static $table_name = 'StaticSiteContentItem';

    /**
     * Default Content type, either 'sitetree', 'file' (or false to disable the default)
     *
     * @var mixed (string | boolean)
     */
    private $default_content_type = 'sitetree';

    /**
     * @var array
     */
    private static $extensions = [
        'hierarchy' => Hierarchy::class,
    ];

    /**
     * @return void
     */
    public function init()
    {
        $url = $this->externalId;
        $processedURL = $this->source->urlList()->processedURL($url);
        $parentURL = $this->source->urlList()->parentProcessedURL($processedURL);
        $subURL = substr(
            $processedURL ? $processedURL['url'] : '',
            strlen($parentURL['url'])
        );

        if ($subURL != '/') {
            $subURL = trim($subURL, '/');
        }

        // Just default values
        $this->Name = $subURL;
        $this->Title = $this->Name;
        $this->AbsoluteURL = rtrim($this->source->BaseUrl, '/') . $this->externalId;
        $this->ProcessedURL = $processedURL['url'] ?? '';
        $this->ProcessedMIME = $processedURL['mime'] ?? '';
    }

    /**
     *
     * @param boolean $showAll
     * @return ArrayList
     */
    public function stageChildren($showAll = false)
    {
        if (!$this->source->urlList()->hasCrawled()) {
            return ArrayList::create();
        }

        $childrenURLs = $this->source->urlList()->getChildren($this->externalId);
        $children = ArrayList::create();

        foreach ($childrenURLs as $child) {
            $children->push($this->source->getObject($child));
        }

        return $children;
    }

    /**
     *
     * @return int
     */
    public function numChildren(): int
    {
        if (!$this->source->urlList()->hasCrawled()) {
            return 0;
        }

        return count($this->source->urlList()->getChildren($this->externalId));
    }

    /**
     * Returns the correct lowercase Silverstripe base class-name based on the current URL's Mime-Type
     * and directs the module to use the correct StaticSiteDataTypeTransformer subclass.
     *
     * @return mixed string
     */
    public function getType(): string
    {
        $mimeTypeProcessor = singleton(StaticSiteMimeProcessor::class);

        if ($mimeTypeProcessor->isOfFileOrImage($this->ProcessedMIME)) {
            return 'file';
        }

        if ($mimeTypeProcessor->isOfHtml($this->ProcessedMIME)) {
            return 'sitetree';
        }

        // Log everything that doesn't fit:
        singleton(StaticSiteUtils::class)->log('UNKNOWN Schema not configured for Mime & URL:', $this->AbsoluteURL, $this->ProcessedMIME);

        return $this->default_content_type;
    }

    /**
     * Returns the correct content-object transformation class.
     *
     * @return ExternalContentTransformer
     */
    public function getTransformer(): ExternalContentTransformer
    {
        $type = $this->getType();

        if ($type == 'file') {
            return StaticSiteFileTransformer::create();
        }

        if ($type == 'sitetree') {
            return StaticSitePageTransformer::create();
        }
    }

    /**
     * @return FieldList $fields
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Add the preview fields here, including rules used
        $urlField = ReadonlyField::create(
            "PreviewSourceURL",
            "Imported from",
            "<a href=\"$this->AbsoluteURL\">" . Convert::raw2xml($this->AbsoluteURL) . "</a>"
        );
        $urlField->dontEscape = true;
        $fields->addFieldToTab("Root.Preview", $urlField);

        $dataType = $this->getType();
        $content = '';

        if ($t = $this->getTransformer()) {
            $content = $t->getContentFieldsAndSelectors($this, $dataType);
        }

        if (count($content) === 0) {
            return $fields;
        }

        foreach ($content as $k => $v) {
            $readonlyField = ReadonlyField::create("Preview$k", "$k<br>\n<em>" . $v['selector'] . "</em>", $v['content']);
            $readonlyField->addExtraClass('readonly-click-toggle');
            $fields->addFieldToTab("Root.Preview", $readonlyField);
        }

        Requirements::javascript('phptek/silverstripe-exodus:js/StaticSiteContentItem.js');

        return $fields;
    }

    /**
     * Performs some checks on $item. If it is of the wrong type, returns false
     *
     * @param string $type e.g. 'sitetree'
     * @return boolean
     */
    public function checkIsType(string $type): bool
    {
        return $this->getType() === strtolower($type);
    }
}
