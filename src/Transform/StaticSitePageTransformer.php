<?php

namespace PhpTek\Exodus\Transform;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Versioned\Versioned;
use PhpTek\Exodus\Transform\StaticSiteDataTypeTransformer;
use SilverStripe\CMS\Model\SiteTree;

/**
 * URL transformer specific to SilverStripe's `SiteTree` class for use with the module's
 * import content feature.
 * If enabled in the CMS UI, links to imported pages will be automatically re-written.
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 * @see {@link StaticSiteDataTypeTransformer}
 */
class StaticSitePageTransformer extends StaticSiteDataTypeTransformer
{
    use Injectable;

    /**
     *
     * @var string
     */
    private static $import_root = 'import-home';

    /**
     * Default value to pass to usleep() to reduce load on the remote server
     *
     * @var number
     */
    private static $sleep_multiplier = 100;

    /**
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->setParentId(1);
    }

    /**
     * Generic function called by \ExternalContentImporter
     *
     * @param StaticSiteContentItem $item
     * @param mixed SilverStripe\ORM\DataObject|null $parentObject
     * @param string $strategy
     * @return mixed StaticSiteTransformResult | boolean
     * @throws \Exception
     */
    public function transform($item, $parentObject, $strategy)
    {
        $this->utils->log("START page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

        if (!$item->checkIsType('sitetree')) {
            $this->utils->log(" - Item not of type \'sitetree\'. for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            $this->utils->log("END page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

            return false;
        }

        $source = $item->getSource();
        // Sleep to reduce load on the remote server
        usleep((int) self::$sleep_multiplier * 1000);
        // Extract content from the page
        $contentFields = $this->getContentFieldsAndSelectors($item, 'SiteTree');

        // Default value for Title
        if (is_array($contentFields)) {
            if (empty($contentFields['Title'])) {
                $contentFields['Title'] = ['content' => $item->Name];
            }

            // Default value for URLSegment
            if (empty($contentFields['URLSegment'])) {
                // $item->Name comes from StaticSiteContentItem::init() and is a URL
                $name = ($item->Name == '/' ? self::$import_root : $item->Name);
                $urlSegment = preg_replace('#\.[^.]*$#', '', $name); // Lose file-extensions e.g .html
                $contentFields['URLSegment'] = ['content' => $urlSegment];
            }

            // Default value for Content (Useful for during unit-testing)
            if (empty($contentFields['Content'])) {
                $contentFields['Content'] = ['content' => 'No content found'];
                $this->utils->log(" - No content found for 'Content' field.", $item->AbsoluteURL, $item->ProcessedMIME);
            }
        }

        // Get a user-defined schema suited to this URL and Mime
        $schema = $source->getSchemaForURL($item->AbsoluteURL, $item->ProcessedMIME);

        if (!$schema) {
            $this->utils->log(" - Couldn't find an import schema for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            $this->utils->log("END page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

            return false;
        }

        // TODO Exception vs return false??
        if (!$schema->DataType) {
            $this->utils->log(" - DataType for migration schema is empty for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            $this->utils->log("END page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            throw new \Exception('DataType for migration schema is empty!');
        }

        // Process incoming according to user-selected duplication strategy
        if (!$page = $this->duplicationStrategy($schema->DataType, $item, $source->BaseUrl, $strategy, $parentObject)) {
            $this->utils->log("END page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

            return false;
        }

        $page->StaticSiteContentSourceID = $source->ID;
        $page->StaticSiteURL = $item->AbsoluteURL;
        $page->StaticSiteImportID = $this->getCurrentImportID();
        $page->Status = 'Published';

        foreach ($contentFields as $property => $map) {
            // Don't write anything new, if we have nothing new to write (useful during unit-testing)
            if (!empty($map['content'])) {
                $page->$property = $map['content'];
            }
        }

        Versioned::set_reading_mode('Stage.Stage');
        $page->write();
        $page->publishRecursive();

        $this->utils->log("END page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

        return StaticSiteTransformResult::create($page, $item->stageChildren());
    }
}
