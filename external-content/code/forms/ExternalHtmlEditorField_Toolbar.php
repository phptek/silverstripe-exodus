<?php

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\RequestHandler;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;

/**
 * Overridden toolbar that handles external content linking
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @deprecated
 *
 */
class ExternalHtmlEditorField_Toolbar extends RequestHandler
{
    protected $controller;
    protected $name;

    public function __construct($controller, $name)
    {
        parent::__construct();

        $this->controller = $controller;
        $this->name = $name;
    }

    /**
     * Return a {@link Form} instance allowing a user to
     * add links in the TinyMCE content editor.
     *
     * @return Form
     */
    public function LinkForm()
    {
        //Requirements::javascript(THIRDPARTY_DIR . "/behaviour.js");
        Requirements::javascript('phptek/silverstripe-exodus:external-content/javascript/external_tiny_mce_improvements.js');

        $form = Form::create(
            $this->controller,
            "{$this->name}/LinkForm",
            FieldList::create(
                LiteralField::create('Heading', '<h2><img src="cms/images/closeicon.gif" alt="' . _t('HtmlEditorField.CLOSE', 'close').'" title="' . _t('HtmlEditorField.CLOSE', 'close') . '" />' . _t('HtmlEditorField.LINK', 'Link') . '</h2>'),
                OptionsetField::create(
                    'LinkType',
                    _t('HtmlEditorField.LINKTO', 'Link to'),
                    array(
                        'internal' => _t('HtmlEditorField.LINKINTERNAL', 'Page on the site'),
                        'external' => _t('HtmlEditorField.LINKEXTERNAL', 'Another website'),
                        'anchor' => _t('HtmlEditorField.LINKANCHOR', 'Anchor on this page'),
                        'email' => _t('HtmlEditorField.LINKEMAIL', 'Email address'),
                        'file' => _t('HtmlEditorField.LINKFILE', 'Download a file'),
                        'externalcontent' =>_t('HtmlEditorField.LINKEXTERNALCONTENT', 'External Content'),
                    )
                ),
                TreeDropdownField::create('internal', _t('HtmlEditorField.PAGE', "Page"), SiteTree::class, 'ID', 'MenuTitle'),
                TextField::create('external', _t('HtmlEditorField.URL', 'URL'), 'http://'),
                EmailField::create('email', _t('HtmlEditorField.EMAIL', 'Email address')),
                TreeDropdownField::create('file', _t('HtmlEditorField.FILE', 'File'), File::class, 'Filename'),
                ExternalTreeDropdownField::create('externalcontent', _t('ExternalHtmlEditorField.EXTERNAL_CONTENT', 'External Content'), ExternalContentSource::class, 'Link()'),
                TextField::create('Anchor', _t('HtmlEditorField.ANCHORVALUE', 'Anchor')),
                TextField::create('LinkText', _t('HtmlEditorField.LINKTEXT', 'Link text')),
                TextField::create('Description', _t('HtmlEditorField.LINKDESCR', 'Link description')),
                CheckboxField::create('TargetBlank', _t('HtmlEditorField.LINKOPENNEWWIN', 'Open link in a new window?'))
            ),
            FieldList::create(
                FormAction::create('insert', _t('HtmlEditorField.BUTTONINSERTLINK', 'Insert link')),
                FormAction::create('remove', _t('HtmlEditorField.BUTTONREMOVELINK', 'Remove link'))
            )
        );

        $form->loadDataFrom($this);

        return $form;
    }

    /**
     * Return a {@link Form} instance allowing a user to
     * add images to the TinyMCE content editor.
     *
     * @return Form
     */
    public function ImageForm()
    {
        //Requirements::javascript(THIRDPARTY_DIR . "/behaviour.js");
        Requirements::javascript('phptek/silverstripe-exodus:external-content/javascript/external_tiny_mce_improvements.js');
        //Requirements::css('cms/css/TinyMCEImageEnhancement.css');
        //Requirements::javascript('cms/javascript/TinyMCEImageEnhancement.js');
        //Requirements::javascript(THIRDPARTY_DIR . '/SWFUpload/SWFUpload.js');
        //Requirements::javascript(CMS_DIR . '/javascript/Upload.js');

        $form = Form::create(
            $this->controller,
            "{$this->name}/ImageForm",
            FieldList::create(
                LiteralField::create('Heading', '<h2><img src="cms/images/closeicon.gif" alt="' . _t('HtmlEditorField.CLOSE', 'close') . '" title="' . _t('HtmlEditorField.CLOSE', 'close') . '" />' . _t('HtmlEditorField.IMAGE', 'Image') . '</h2>'),
                TreeDropdownField::create('FolderID', _t('HtmlEditorField.FOLDER', 'Folder'), Folder::class),
                LiteralField::create(
                    'AddFolderOrUpload',
                    '<div style="clear:both;"></div><div id="AddFolderGroup" style="display: none">
						<a style="" href="#" id="AddFolder" class="link">' . _t('HtmlEditorField.CREATEFOLDER', 'Create Folder') . '</a>
						<input style="display: none; margin-left: 2px; width: 94px;" id="NewFolderName" class="addFolder" type="text">
						<a style="display: none;" href="#" id="FolderOk" class="link addFolder">' . _t('HtmlEditorField.OK', 'Ok') . '</a>
						<a style="display: none;" href="#" id="FolderCancel" class="link addFolder">' . _t('HtmlEditorField.FOLDERCANCEL', 'Cancel') . '</a>
					</div>
					<div id="PipeSeparator" style="display: none">|</div>
					<div id="UploadGroup" class="group" style="display: none; margin-top: 2px;">
						<a href="#" id="UploadFiles" class="link">' . _t('HtmlEditorField.UPLOAD', 'Upload') . '</a>
					</div>'
                ),
                TextField::create('getimagesSearch', _t('HtmlEditorField.SEARCHFILENAME', 'Search by file name')),
                ThumbnailStripField::create('FolderImages', 'FolderID', 'getimages'),
                TextField::create('AltText', _t('HtmlEditorField.IMAGEALTTEXT', 'Alternative text (alt) - shown if image cannot be displayed'), '', 80),
                TextField::create('ImageTitle', _t('HtmlEditorField.IMAGETITLE', 'Title text (tooltip) - for additional information about the image')),
                TextField::create('CaptionText', _t('HtmlEditorField.CAPTIONTEXT', 'Caption text')),
                DropdownField::create(
                    'CSSClass',
                    _t('HtmlEditorField.CSSCLASS', 'Alignment / style'),
                    array(
                        'left' => _t('HtmlEditorField.CSSCLASSLEFT', 'On the left, with text wrapping around.'),
                        'leftAlone' => _t('HtmlEditorField.CSSCLASSLEFTALONE', 'On the left, on its own.'),
                        'right' => _t('HtmlEditorField.CSSCLASSRIGHT', 'On the right, with text wrapping around.'),
                        'center' => _t('HtmlEditorField.CSSCLASSCENTER', 'Centered, on its own.'),
                    )
                ),
                FieldGroup::create(
                    _t('HtmlEditorField.IMAGEDIMENSIONS', 'Dimensions'),
                    TextField::create('Width', _t('HtmlEditorField.IMAGEWIDTHPX', 'Width'), 100),
                    TextField::create('Height', " x " . _t('HtmlEditorField.IMAGEHEIGHTPX', 'Height'), 100)
                )
            ),
            FieldList::create(
                FormAction::create('insertimage', _t('HtmlEditorField.BUTTONINSERTIMAGE', 'Insert image'))
            )
        );

        $form->disableSecurityToken();
        $form->loadDataFrom($this);

        return $form;
    }

    public function FlashForm()
    {
        //Requirements::javascript(THIRDPARTY_DIR . "/behaviour.js");
        Requirements::javascript("phptek/silverstripe-exodus:external-content/javascript/external_tiny_mce_improvements.js");
        //Requirements::javascript(THIRDPARTY_DIR . '/SWFUpload/SWFUpload.js');
        //Requirements::javascript(CMS_DIR . '/javascript/Upload.js');

        $form = Form::create(
            $this->controller,
            "{$this->name}/FlashForm",
            FieldList::create(
                LiteralField::create('Heading', '<h2><img src="cms/images/closeicon.gif" alt="'._t('HtmlEditorField.CLOSE', 'close').'" title="'._t('HtmlEditorField.CLOSE', 'close').'" />'._t('HtmlEditorField.FLASH', 'Flash').'</h2>'),
                TreeDropdownField::create("FolderID", _t('HtmlEditorField.FOLDER'), Folder::class),
                TextField::create('getflashSearch', _t('HtmlEditorField.SEARCHFILENAME', 'Search by file name')),
                ThumbnailStripField::create("Flash", "FolderID", "getflash"),
                FieldGroup::create(
                    _t('HtmlEditorField.IMAGEDIMENSIONS', "Dimensions"),
                    TextField::create("Width", _t('HtmlEditorField.IMAGEWIDTHPX', "Width"), 100),
                    TextField::create("Height", "x " . _t('HtmlEditorField.IMAGEHEIGHTPX', "Height"), 100)
                )
            ),
            FieldList::create(
                FormAction::create("insertflash", _t('HtmlEditorField.BUTTONINSERTFLASH', 'Insert Flash'))
            )
        );
        $form->loadDataFrom($this);
        return $form;
    }
}
