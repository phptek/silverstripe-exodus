<?php
/**
 * Base content transformer. Comprises logic common to all types of legacy/scraped text
 * and binary content for import into native SilverStripe DataObjects.
 * 
 * Use this as your starting-point for creating custom content transformers for other data-types.
 * 
 * Hint: You'll need to use the returned object from getContentFieldsAndSelectors() if the dataType
 * you wish to work with is not 'File' or 'SiteTree'.
 * 
 * @package staticsiteconnector
 * @author Sam Minee <sam@silverstripe.com>
 * @author Science Ninjas <scienceninjas@silverstripe.com>
 * @see {@link StaticSitePageTransformer}
 * @see {@link StaticSiteFileTransformer}
 */
abstract class StaticSiteDataTypeTransformer implements ExternalContentTransformer {

	/**
	 * Holds the StaticSiteUtils object on construct
	 * 
	 * @var StaticSiteUtils
	 */
	public $utils;	
	
	/**
	 * @var StaticSiteMimeProcessor
	 *
	 * $mimeTypeProcessor
	 */
	public $mimeProcessor;	
	
	/**
	 *
	 * The parent ID of an imported object
	 * 
	 * @var number
	 */
	public $parentId = 0;	

	/**
	 * 
	 * @return void
	 */
	public function __construct() {
		$this->utils = Injector::inst()->get('StaticSiteUtils', true);
		$this->mimeProcessor = Injector::inst()->get('StaticSiteMimeProcessor', true);
	}

	/**
	 * Get content from remote datasource (e.g. a File, Image or page-text).
	 * If $dataType is anything but 'File' or 'SiteTree' a StaticSiteContentExtractor object
	 * is returned so sublclasses of StaticSiteDataTypeTransformer can implement custom logic
	 * based off it.
	 *
	 * @param StaticSiteContentItem $item The item to extract
	 * @param string $dataType e.g. 'File' or 'SiteTree'
	 * @return null | StaticSiteContentExtractor | array Map of SS field name=>array('selector' => selector, 'content' => field content)
	 */
	public function getContentFieldsAndSelectors($item, $dataType) {
		$dataType = strtolower($dataType);
		// Get the import rules from the content source
		$importSchema = $item->getSource()->getSchemaForURL($item->AbsoluteURL, $item->ProcessedMIME);
		if(!$importSchema) {
			$this->utils->log("Couldn't find an import schema for ", $item->AbsoluteURL, $item->ProcessedMIME, 'WARNING');
			return null;
		}
		$importRules = $importSchema->getImportRules();

 		// Extract from the remote content based on those rules
		$contentExtractor = new StaticSiteContentExtractor($item->AbsoluteURL, $item->ProcessedMIME);
		
		if($dataType == 'file') {
			$extraction = $contentExtractor->extractMapAndSelectors($importRules, $item);
			$extraction['tmp_path'] = $contentExtractor->getTmpFileName();
		}
		else if($dataType == 'sitetree') {
			$extraction = $contentExtractor->extractMapAndSelectors($importRules, $item);			
		}
		else {
			// Allows for further data-types
			return $contentExtractor;
		}
		
		return $extraction;
	}
	
	/**
	 * Process incoming content according to CMS user-inputted duplication strategy.
	 * 
	 * @param string $dataType
	 * @param string $strategy
	 * @param StaticSiteContentItem $item
	 * @param string $baseUrl
	 * @param DataObject $parentObject
	 * @return boolean | DataObject
	 */
	protected function duplicationStrategy($dataType, $item, $baseUrl, 
			$strategy = ExternalContentTransformer::SKIP, DataObject $parentObject = null) {
		/*
		 * If import config is imported into the DB from another SS setup or imported using some future 
		 * import/export feature, ensure we fail cleanly if the schema requires a class that doesn't exist
		 * in the current setup.
		 */
		if(!ClassInfo::exists($dataType)) {
			return;
		}
		
		// Has the object already been imported?
		$baseUrl = rtrim($baseUrl, '/');
		$existing = $dataType::get()->filter('StaticSiteURL', $baseUrl . $item->getExternalId())->first();
		if($existing) {		
			if($strategy === ExternalContentTransformer::DS_OVERWRITE) {
				// "Overwrite" == Update
				$object = $existing;
				$object->ParentID = $existing->ParentID;
			}
			else if($strategy === ExternalContentTransformer::DS_DUPLICATE) {
				$object = $existing->duplicate(false);
				$object->ParentID = ($parentObject ? $parentObject->ID : $this->getParentId());
			}
			else {
				// Deals-to "skip" and no selection
				return false;
			}
		}
		else {
			$object = new $dataType(array());
			$object->ParentID = ($parentObject ? $parentObject->ID : $this->getParentId());
		}
		return $object;
	}
	
	/**
	 * Get current import ID. If none can be found, start one and return that.
	 * 
	 * @return number
	 */
	public function getCurrentImportID() {
		if(!$import = StaticSiteImportDataObject::current()) {
			return 1;
		}
		return $import->ID;	
	}
	
	/**
	 * Build an array of file extensions. Utilised in buildFileProperties() to check 
	 * incoming file-extensions are valid against those found on {@link File}.
	 * 
	 * @return array $exts
	 */
	public function getSSExtensions() {
		$extensions = singleton('File')->config()->app_categories;
		$exts = array();
		foreach($extensions as $category => $extArray) {
			foreach($extArray as $ext) {
				$exts[] = $ext;
			}
		}
		return $exts;
	}	
	
	/**
	 * 
	 * Sets the parent ID for an imported object.
	 * 
	 * @param number $id
	 * @return void
	 */
	public function setParentId($id) {
		$this->parentId = $id;
	}
	
	/**
	 * 
	 * Gets the parent ID for an imported object.
	 * 
	 * @return number $id
	 */
	public function getParentId() {
		return $this->parentId;
	}
}
