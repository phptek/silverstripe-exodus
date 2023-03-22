<?php

use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Model\CurrentPageIdentifier;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\View\Requirements;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Form;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\Hierarchy\MarkedSet;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Backend administration pages for the external content module
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD License http://silverstripe.org/bsd-license
 * @todo (Russell M) Subclass CMSMain instead and overload only the methods that _need_ it (viz SiteTree vs ExternalContentSource)
 */
class ExternalContentAdmin extends LeftAndMain implements CurrentPageIdentifier, PermissionProvider
{
    /**
     * The URL format to get directly to this controller
     * @var unknown_type
     */
    public const URL_STUB = 'extadmin';

    /**
     * The directory that the module is assuming it's installed in to.
     */
    public static $directory = 'external-content';

    /**
     * URL segment used by the backend
     *
     * @var string
     */
    private static $url_segment = 'migration';
    private static $url_rule = '$Action//$ID';
    private static $menu_title = 'Migration';
    private static $tree_class = ExternalContentSource::class;
    private static $allowed_actions = [
        'addprovider',
        'deleteprovider',
        'deletemarked',
        'CreateProviderForm',
        'DeleteItemsForm',
        'getsubtree',
        'save',
        'migrate',
        'download',
        'view',
        'treeview',
        'EditForm',
        'AddForm',
        'updateSources',
        'updatetreenodes'
    ];

    public function init()
    {
        parent::init();

        Requirements::customCSS($this->generatePageIconsCss());
        Requirements::javascript('phptek/silverstripe-exodus:external-content/javascript/external-content-admin.js');
        Requirements::javascript('phptek/silverstripe-exodus:external-content/javascript/external-content-reload.js');
    }

    /**
     * Overridden to properly output a value and end, instead of
     * letting further headers (X-Javascript-Include) be output
     */
    public function pageStatus()
    {
        // If no ID is set, we're merely keeping the session alive
        if (!isset($_REQUEST['ID'])) {
            echo '{}';
            return;
        }

        parent::pageStatus();
    }

    /**
     * @return string
     */
    public function LinkTreeViewDeferred(): string
    {
        return $this->Link('treeview');
    }

    /**
     * Copied directly from {@link CMSMain}. It appears this method used to be on {@link LeftAndMain}
     * when the "external-content" module was first written. Since we need to to subclass L&M and not CMSMain
     * we need a copy of it here.
     *
     * @inheritDoc
     */
    public function getSiteTreeFor(
        $className,
        $rootID = null,
        $childrenMethod = null,
        $numChildrenMethod = null,
        $filterFunction = null,
        $nodeCountThreshold = null
    ) {
        $nodeCountThreshold = is_null($nodeCountThreshold) ? Config::inst()->get($className, 'node_threshold_total') : $nodeCountThreshold;
        // Provide better defaults from filter
        $filter = $this->getSearchFilter();

        if ($filter) {
            if (!$childrenMethod) {
                $childrenMethod = $filter->getChildrenMethod();
            }

            if (!$numChildrenMethod) {
                $numChildrenMethod = $filter->getNumChildrenMethod();
            }

            if (!$filterFunction) {
                $filterFunction = function ($node) use ($filter) {
                    return $filter->isPageIncluded($node);
                };
            }
        }

        // Build set from node and begin marking
        $record = $rootID ? $this->getRecord($rootID) : null;
        $rootNode = $record ? $record : DataObject::singleton($className);
        $markingSet = MarkedSet::create($rootNode, $childrenMethod, $numChildrenMethod, $nodeCountThreshold);

        // Set filter function
        if ($filterFunction) {
            $markingSet->setMarkingFilterFunction($filterFunction);
        }

        // Mark tree from this node
        $markingSet->markPartialTree();

        // Ensure current page is exposed
        $currentPage = $this->currentPage();
        if ($currentPage) {
            $markingSet->markToExpose($currentPage);
        }

        // Pre-cache permissions
        // $checker = SiteTree::getPermissionChecker();
        // if ($checker instanceof InheritedPermissions) {
        //     $checker->prePopulatePermissionCache(
        //         InheritedPermissions::EDIT,
        //         $markingSet->markedNodeIDs()
        //     );
        // }

        // Render using full-subtree template
        return $markingSet->renderChildren(
            [ self::class . '_SubTree', 'type' => 'Includes' ],
            $this->getTreeNodeCustomisations()
        );
    }

    /**
     * Get callback to determine template customisations for nodes
     *
     * @return callable
     */
    protected function getTreeNodeCustomisations()
    {
        $rootTitle = $this->getCMSTreeTitle();
        return function ($node) use ($rootTitle) {
            return [
                'listViewLink' => $this->LinkListViewChildren($node->ID),
                'rootTitle' => $rootTitle,
                'extraClass' => $this->getTreeNodeClasses($node),
                'Title' => _t(
                    self::class . '.PAGETYPE_TITLE',
                    '(Page type: {type}) {title}',
                    [
                        'type' => $node->i18n_singular_name(),
                        'title' => $node->Title,
                    ]
                )
            ];
        };
    }

    /**
     * Link to list view for children of a parent page
     *
     * @param int|string $parentID Literal parentID, or placeholder (e.g. '%d') for
     * client side substitution
     * @return string
     */
    public function LinkListViewChildren($parentID)
    {
        return sprintf(
            '%s?ParentID=%s',
            CMSMain::singleton()->Link(),
            $parentID
        );
    }

    public function ContentSources()
    {
        return DataObject::get(ExternalContentSource::class);
    }

    /**
     * Get extra CSS classes for a page's tree node
     *
     * @param $node
     * @return string
     */
    public function getTreeNodeClasses($node): string
    {
        // Get classes from object
        $classes = $node->CMSTreeClasses() ?? '';

        // Get additional filter classes
        $filter = $this->getSearchFilter();

        if ($filter && ($filterClasses = $filter->getPageClasses($node))) {
            if (is_array($filterClasses)) {
                $filterClasses = implode(' ', $filterClasses);
            }

            $classes .= ' ' . $filterClasses;
        }

        return trim($classes);
    }

    /**
     *
     * If there's no ExternalContentSource ID available from Session or Request data then instead of
     * LeftAndMain::currentPageID() returning just `null`, "extend" its range to use the first sub-class
     * of {@link ExternalContentSource} the system can find, either via config or introspection.
     *
     * @return number | null
     */
    public function getCurrentPageID()
    {
        if (!$id = $this->currentPageID()) {
            // Try an id from an ExternalContentSource Subclass
            $defaultSources = ClassInfo::getValidSubClasses(ExternalContentSource::class);
            array_shift($defaultSources);
            // Use one if defined in config, otherwise use first one found through reflection
            $defaultSourceConfig = ExternalContentSource::config()->get('default_source');

            if ($defaultSourceConfig) {
                $class = $defaultSourceConfig;
            } elseif (isset($defaultSources[0])) {
                $class = $defaultSources[0];
            } else {
                if ($candidate = array_values($defaultSources ?? [])[0]) {
                    $class = $candidate;
                } else {
                    $class = null;
                }
            }

            if ($class && $source = DataObject::get($class)->first()) {
                return $source->ID;
            }

            return null;
        }

        return $id;
    }

    public function currentPageID()
    {
        if ($this->getRequest()->requestVar('ID') && preg_match(ExternalContent::ID_FORMAT, $this->getRequest()->requestVar('ID'))) {
            return $this->getRequest()->requestVar('ID');
        } elseif (isset($this->urlParams['ID']) && preg_match(ExternalContent::ID_FORMAT, $this->urlParams['ID'])) {
            return $this->urlParams['ID'];
        } else {
            return ExternalContentSource::get()->sort('ID', 'ASC')->last()->ID;
        }

        return 0;
    }

    /**
     * Custom currentPage() method to handle opening the 'root' node (which is always going to be an instance of ExternalContentSource???)
     *
     * @return DataObject
     */
    public function currentPage()
    {
        $id = (string) $this->getCurrentPageID();

        if (preg_match(ExternalContent::ID_FORMAT, $id)) {
            return ExternalContent::getDataObjectFor($id);
        }

        if ($id == 'root') {
            return singleton($this->config()->get('tree_class'));
        }
    }

    /**
     * Is the passed in ID a valid
     * format?
     *
     * @return boolean
     */
    public static function isValidId($id)
    {
        return preg_match(ExternalContent::ID_FORMAT, $id);
    }

    /**
     * Action to migrate a selected object through to SS.
     *
     * @param array $request
     */
    public function migrate(array $request)
    {
        $form = $this->getEditForm();
        $migrationTarget 		= isset($request['MigrationTarget']) ? $request['MigrationTarget'] : '';
        $fileMigrationTarget 	= isset($request['FileMigrationTarget']) ? $request['FileMigrationTarget'] : '';
        $includeSelected 		= isset($request['IncludeSelected']) ? $request['IncludeSelected'] : 0;
        $includeChildren 		= isset($request['IncludeChildren']) ? $request['IncludeChildren'] : 0;
        $duplicates 			= isset($request['DuplicateMethod']) ? $request['DuplicateMethod'] : ExternalContentTransformer::DS_OVERWRITE;
        $selected 				= isset($request['ID']) ? $request['ID'] : 0;

        if (!$selected) {
            $messageType = 'bad';
            $message = _t('ExternalContent.NOITEMSELECTED', 'No item selected to import into.');

            $form->sessionError($message, $messageType);

            return $this->getResponseNegotiator()->respond($this->request);
        } elseif (!($migrationTarget && $fileMigrationTarget)) {
            $messageType = 'bad';
            $message = _t('ExternalContent.NOTARGETSELECTED', 'No target(s) selected to import crawled content into.');

            $form->sessionError($message, $messageType);
            return $this->getResponseNegotiator()->respond($this->request);
        } else {
            if ($migrationTarget) {
                $targetType = SiteTree::class;
                $target = DataObject::get_by_id($targetType, $migrationTarget);
            } elseif ($fileMigrationTarget) {
                $targetType = File::class;
                $target = DataObject::get_by_id($targetType, $fileMigrationTarget);
            }

            $from = ExternalContent::getDataObjectFor($selected);
            // Don't use an instance of ExternalContentSource which has nothing to do with site-tree's but is yet
            // strangely selectable from Silverstripe's UI
            if ($from instanceof ExternalContentSource) {
                $selected = false;
            }

            if (isset($request['Repeat']) && $request['Repeat'] > 0) {
                $job = ScheduledExternalImportJob::create($request['Repeat'], $from, $target, $includeSelected, $includeChildren, $targetType, $duplicates, $request);
                singleton(QueuedJobService::class)->queueJob($job);

                $messageType = 'good';
                $message = _t('ExternalContent.CONTENTMIGRATEQUEUED', 'Import job queued.');
            } else {
                if ($importer = $from->getContentImporter($targetType)) {
                    $result = $importer->import($from, $target, $includeSelected, $includeChildren, $duplicates, $request);

                    if ($result instanceof QueuedExternalContentImporter) {
                        $messageType = 'good';
                        $message = _t('ExternalContent.CONTENTMIGRATEQUEUED', 'Import job queued.');
                    } else {
                        $messageType = 'good';
                        $message = _t('ExternalContent.CONTENTMIGRATED', 'Import Successful. Please review the CMS site-tree.');
                    }
                }
            }
        }

        $form->sessionError($message, $messageType);
        return $this->getResponseNegotiator()->respond($this->request);
    }

    /**
     * Return the record corresponding to the given ID.
     *
     * Both the numeric IDs of ExternalContentSource records and the composite IDs of ExternalContentItem entries
     * are supported.
     *
     * @param  string $id The ID
     * @param string $versionID
     * @return Dataobject The relevant object
     */
    public function getRecord($id, $versionID = null)
    {
        if (is_numeric($id)) {
            return parent::getRecord($id);
        }

        return ExternalContent::getDataObjectFor($id);
    }


    /**
     * Return the edit form or null on error.
     *
     * @param array $request
     * @return mixed Form|boolean
     * @see LeftAndMain#EditForm()
     */
    public function EditForm($request = null)
    {
        if ($curr = $this->getCurrentPageID()) {
            if (!$record = $this->currentPage()) {
                return false;
            }

            if (!$record->canView()) {
                return Security::permissionFailure($this);
            }
        }

        if ($this->hasMethod('getEditForm')) {
            return $this->getEditForm($curr);
        }

        return false;
    }

    /**
     * Return the form for editing
     */
    public function getEditForm($id = null, $fields = null)
    {
        if (!$id) {
            $id = $this->getCurrentPageID();
        }

        if ($id && $id != "root") {
            $record = $this->getRecord($id);
        }

        if (isset($record)) {
            $fields = $record->getCMSFields();

            // If we're editing an external source or item, and it can be imported
            // then add the "Import" tab.
            $isSource = $record instanceof ExternalContentSource;
            $isItem = $record instanceof ExternalContentItem;

            // TODO Get the crawl status and show the "Import" tab if crawling has completed
            if (($isSource || $isItem) && $record->canImport()) {
                $allowedTypes = $record->allowedImportTargets();

                if (isset($allowedTypes['sitetree'])) {
                    $fields->addFieldToTab(
                        'Root.Import',
                        TreeDropdownField::create(
                            "MigrationTarget",
                            _t('ExternalContent.MIGRATE_TARGET', 'Parent Page To Import Into'),
                            SiteTree::class,
                        )
                            ->setValue($this->getRequest()->postVars()['MigrationTarget'] ?? null)
                            ->setDescription('All imported page-like content will be organised hierarchically under here.')
                    );
                }

                if (isset($allowedTypes['file'])) {
                    $fields->addFieldToTab(
                        'Root.Import',
                        TreeDropdownField::create(
                            "FileMigrationTarget",
                            _t('ExternalContent.FILE_MIGRATE_TARGET', 'Parent Folder To Import Into'),
                            Folder::class,
                        )
                            ->setValue($this->getRequest()->postVars()['FileMigrationTarget'] ?? null)
                            ->setDescription('All imported file-like content will be organised hierarchically beneath here.')
                    );
                }

                $fields->addFieldToTab(
                    'Root.Import',
                    CheckboxField::create(
                        "IncludeChildren",
                        _t('ExternalContent.INCLUDE_CHILDREN', 'Include child items in import'),
                        true
                    )
                );

                $duplicateOptions = [
                    ExternalContentTransformer::DS_OVERWRITE => ExternalContentTransformer::DS_OVERWRITE,
                    ExternalContentTransformer::DS_DUPLICATE => ExternalContentTransformer::DS_DUPLICATE,
                    ExternalContentTransformer::DS_SKIP => ExternalContentTransformer::DS_SKIP,
                ];

                $fields->addFieldToTab(
                    'Root.Import',
                    OptionsetField::create(
                        "DuplicateMethod",
                        _t('ExternalContent.DUPLICATES', 'Duplicate Item Handling'),
                        $duplicateOptions,
                        $duplicateOptions[ExternalContentTransformer::DS_SKIP]
                    )->setValue($this->getRequest()->postVars()['DuplicateMethod'] ?? ExternalContentTransformer::DS_SKIP)
                );

                $migrateButton = FormAction::create('migrate', _t('ExternalContent.IMPORT', 'Start Importing'))
                    ->setAttribute('data-icon', 'arrow-circle-double')
                    ->addExtraClass('btn action btn btn-primary tool-button font-icon-plus')
                    ->setUseButtonTag(true);

                $fields->addFieldToTab(
                    'Root.Import',
                    LiteralField::create(
                        'MigrateActions',
                        '<div class="btn-toolbar">' . $migrateButton->forTemplate() . '</div>'
                    )
                );
            }

            $fields->push($hf = HiddenField::create("ID"));
            $hf->setValue($id);

            $fields->push($hf = HiddenField::create("Version"));
            $hf->setValue(1);

            $actions = FieldList::create();

            // Only show save button if not 'assets' folder
            if ($record->canEdit()) {
                $actions->push(
                    FormAction::create('save', _t('ExternalContent.SAVE', 'Save'))
                        ->addExtraClass('save btn action btn-outline-primary font-icon-tick')
                        ->setAttribute('data-icon', 'accept')
                        ->setUseButtonTag(true)
                );
            }

            if ($isSource && $record->canDelete()) {
                $actions->push(
                    FormAction::create('delete', _t('ExternalContent.DELETE', 'Delete'))
                        ->addExtraClass('delete btn action btn-outline-primary font-icon-tick')
                        ->setAttribute('data-icon', 'decline')
                        ->setUseButtonTag(true)
                );
            }

            $form = Form::create($this, "EditForm", $fields, $actions);

            if ($record->exists()) {
                $form->loadDataFrom($record);
            } else {
                $form->loadDataFrom([
                    "ID" => "root",
                    "URL" => Director::absoluteBaseURL() . self::$url_segment,
                ]);
            }

            if (!$record->canEdit()) {
                $form->makeReadonly();
            }
        } else {
            // Create a dummy form
            $form = Form::create($this, "EditForm", FieldList::create(), FieldList::create());
            $form->setMessage('Select or create a new Migration Profile on the left-hand side.', 'info');
        }

        $form
            ->addExtraClass('cms-edit-form center ss-tabset ' . $this->BaseCSSClasses())
            ->setTemplate($this->getTemplatesWithSuffix('_EditForm'))
            ->setAttribute('data-pjax-fragment', 'CurrentForm');

        $this->extend('updateEditForm', $form);

        return $form;
    }

    /**
     * Get the form used to create a new provider
     *
     * @return Form
     */
    public function AddForm()
    {
        $classes = ClassInfo::subclassesFor(self::$tree_class);
        array_shift($classes);

        foreach ($classes as $key => $class) {
            $model = $class::create();

            if (!$model->canCreate()) {
                unset($classes[$key]);
            }

            $classes[$key] = $model->i18n_singular_name();
        }

        $fields = FieldList::create(
            HiddenField::create("ParentID"),
            HiddenField::create("Locale", 'Locale', i18n::get_locale()),
            DropdownField::create("ProviderType", "", $classes)
        );

        $actions = FieldList::create(
            FormAction::create("addprovider", _t('ExternalContent.CREATE', "Create"))
                ->addExtraClass('btn btn-primary tool-button font-icon-plus')
                ->setAttribute('data-icon', 'accept')
                ->setUseButtonTag(true)
        );

        $form = Form::create($this, "AddForm", $fields, $actions);

        $this->extend('updateEditForm', $form);

        return $form;
    }

    /**
     * Add a new provider (triggered by the ExternalContentAdmin_left template)
     *
     * @return unknown_type
     */
    public function addprovider()
    {
        // Providers are ALWAYS at the root
        $parent = 0;
        $name = (isset($_REQUEST['Name'])) ?
            basename($_REQUEST['Name']) :
            _t('ExternalContent.NEWCONNECTOR', "New Profile Name");
        $type = $_REQUEST['ProviderType'];
        $providerClasses = array_map(
            function ($item) {
                return strtolower($item);
            },
            ClassInfo::subclassesFor(self::$tree_class)
        );

        if (!in_array($type, $providerClasses)) {
            throw new \Exception("Invalid connector type");
        }

        $parentObj = null;

        // Create object
        $record = $type::create();
        $record->ParentID = $parent;
        $record->Name = $record->Title = $name;
        $shortName = $record->i18n_singular_name();

        $message = sprintf(_t('ExternalContent.SourceAdded', 'Successfully created a new %s.'), $shortName);
        $messageType = 'good';

        try {
            $record->write();
        } catch (ValidationException $ex) {
            $message = sprintf(_t('ExternalContent.SourceAddedFailed', 'Not successfully created %s'), $type);
            $messageType = 'bad';
        }

        $this->setCurrentPageID($record->ID);
        $this->getEditForm()->sessionError($message, $messageType);

        return $this->getResponseNegotiator()->respond($this->request);
    }

    /**
     * Copied from AssetAdmin...
     *
     * @return Form
     */
    public function DeleteItemsForm()
    {
        $form = Form::create(
            $this,
            'DeleteItemsForm',
            FieldList::create(
                LiteralField::create(
                    'SelectedPagesNote',
                    sprintf('<p>%s</p>', _t('ExternalContentAdmin.SELECT_CONNECTORS', 'Select the connectors that you want to delete and then click the button below'))
                ),
                HiddenField::create('csvIDs')
            ),
            FieldList::create(
                FormAction::create('deleteprovider', _t('ExternalContentAdmin.DELCONNECTORS', 'Delete the selected connectors'))
            )
        );

        $form->addExtraClass('actionparams');

        return $form;
    }

    /**
     * Delete a folder
     */
    public function deleteprovider()
    {
        $script = '';
        $ids = explode(' *, *', $_REQUEST['csvIDs']);
        $script = '';

        if (!$ids) {
            return false;
        }

        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $record = ExternalContent::getDataObjectFor($id);

                if ($record) {
                    $script .= $this->deleteTreeNodeJS($record);
                    $record->delete();
                    $record->destroy();
                }
            }
        }

        $size = sizeof($ids);

        if ($size > 1) {
            $message = $size . ' ' . _t('AssetAdmin.FOLDERSDELETED', 'folders deleted.');
        } else {
            $message = $size . ' ' . _t('AssetAdmin.FOLDERDELETED', 'folder deleted.');
        }

        $script .= "statusMessage('$message');";
        echo $script;
    }

    public function getCMSTreeTitle()
    {
        return 'Connectors';
    }

    /**
     * @return string
     */
    public function treeview()
    {
        return $this->renderWith($this->getTemplatesWithSuffix('_TreeView'));
    }

    /**
     * @return string
     */
    public function SiteTreeAsUL()
    {
        $html = $this->getSiteTreeFor($this->config()->get('tree_class'), null, null, 'NumChildren');
        //$this->extend('updateSiteTreeAsUL', $html);

        return $html;
    }

    /**
     * Get a subtree underneath the request param 'ID'.
     * If ID = 0, then get the whole tree.
     */
    public function getsubtree($request)
    {
        $html = $this->getSiteTreeFor(
            ExternalContentItem::class,
            $request->getVar('ID'),
            null,
            'NumChildren',
            null,
            $request->getVar('minNodeCount')
        );

        // Trim off the outer tag
        $html = preg_replace('/^[\s\t\r\n]*<ul[^>]*>/', '', $html);
        $html = preg_replace('/<\/ul[^>]*>[\s\t\r\n]*$/', '', $html);

        return $html;
    }


    /**
     * Include CSS for page icons. We're not using the JSTree 'types' option
     * because it causes too much performance overhead just to add some icons.
     *
     * @return String CSS
     */
    public function generatePageIconsCss()
    {
        $css = '';

        $sourceClasses 	= ClassInfo::subclassesFor(ExternalContentSource::class);
        $itemClasses 	= ClassInfo::subclassesFor(ExternalContentItem::class);
        $classes 		= array_merge($sourceClasses, $itemClasses);

        foreach ($classes as $class) {
            $obj = singleton($class);
            $iconSpec = $obj->config()->get('icon');

            if (!$iconSpec) {
                continue;
            }

            // Legacy support: We no longer need separate icon definitions for folders etc.
            $iconFile = (is_array($iconSpec)) ? $iconSpec[0] : $iconSpec;

            // Legacy support: Add file extension if none exists
            if (!pathinfo($iconFile, PATHINFO_EXTENSION)) {
                $iconFile .= '-file.gif';
            }

            $iconPathInfo = pathinfo($iconFile);

            // Base filename
            $baseFilename = $iconPathInfo['dirname'] . '/' . $iconPathInfo['filename'];
            $fileExtension = $iconPathInfo['extension'];

            $selector = ".page-icon.class-$class, li.class-$class > a .jstree-pageicon";

            if (Director::fileExists($iconFile)) {
                $css .= "$selector { background: transparent url('$iconFile') 0 0 no-repeat; }\n";
            } else {
                // Support for more sophisticated rules, e.g. sprited icons
                $css .= "$selector { $iconFile }\n";
            }
        }

        $css .= "li.type-file > a .jstree-pageicon { background: transparent url('framework/admin/images/sitetree_ss_pageclass_icons_default.png') 0 0 no-repeat; }\n}";

        return $css;
    }

    /**
     * Save the content source/item.
     */
    public function save($urlParams, $form)
    {
        // Retrieve the record.
        $record = null;

        if (isset($urlParams['ID'])) {
            $record = ExternalContent::getDataObjectFor($urlParams['ID']);
        }

        if (!$record) {
            return parent::save($urlParams, $form);
        }

        if ($record->canEdit()) {
            // lets load the params that have been sent and set those that have an editable mapping
            if ($record->hasMethod('editableFieldMapping')) {
                $editable = $record->editableFieldMapping();
                $form->saveInto($record, array_keys($editable));
                $record->remoteWrite();
            } else {
                $form->saveInto($record);
                $record->write();
            }

            // Set the form response.
            $this->response->addHeader('X-Status', rawurlencode(_t('LeftAndMain.SAVEDUP', 'Saved.')));
        } else {
            $this->response->addHeader('X-Status', rawurlencode(_t('LeftAndMain.SAVEDUP', 'You don\'t have write access.')));
        }

        return $this->getResponseNegotiator()->respond($this->request);
    }

    /**
     * Delete the content source/item.
     */
    public function delete($data, $form)
    {
        $className = $this->config()->get('tree_class');
        $record = DataObject::get_by_id($className, Convert::raw2sql($data['ID']));

        if ($record && !$record->canDelete()) {
            return Security::permissionFailure();
        }

        if (!$record || !$record->ID) {
            $this->httpError(404, "Bad record ID #" . (int)$data['ID']);
        }

        $type = $record->ClassName;
        $record->delete();
        $message = "Deleted $type successfully.";
        $messageType = 'bad';
        $this->getEditForm()->sessionError($message, $messageType);

        $this->response->addHeader('X-Status', rawurlencode(_t('LeftAndMain.DELETED', 'Deleted.')));

        return $this->getResponseNegotiator()->respond(
            $this->request,
            array('currentform' => array($this, 'EmptyForm'))
        );
    }

    /**
     * Retrieve the updated source list, used in an AJAX request to update the current view.
     *
     * @return string
     */
    public function updateSources(): string
    {
        $HTML = $this->treeview()->value;

        return preg_replace('/^\s+|\n|\r|\s+$/m', '', $HTML);
    }

    /**
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function updatetreenodes(HTTPRequest $request): HTTPResponse
    {
        // noop
        return singleton(CMSMain::class)->updatetreenodes($request);
    }
}
