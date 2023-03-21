<?php

namespace PhpTek\Exodus\Transform;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\AssetAdmin\Helper\ImageThumbnailHelper;

/**
 * URL transformer specific to SilverStripe's `File` class for use with the module's
 * import content feature. It will re-create all available data of the scraped file into SilverStripe's
 * database and re-create a copy of the file itself on the filesystem.
 * If enabled in the CMS UI, links to imported images and documents in imported page-content will also be automatically
 * re-written.
 *
 * @todo write unit-test for unwritable assets dir.
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 * @see {@link StaticSiteDataTypeTransformer}
 */
class StaticSiteFileTransformer extends StaticSiteDataTypeTransformer
{
    /**
     * Default value to pass to usleep() to reduce load on the remote server
     *
     * @var number
     */
    private static $sleep_multiplier = 10;

    /**
     * Generic function called by \ExternalContentImporter
     *
     * @inheritdoc
     */
    public function transform($item, $parentObject, $strategy)
    {
        $this->utils->log("START file-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

        if (!$item->checkIsType('file')) {
            $this->utils->log(" - Item not of type \'file\'. for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            $this->utils->log("END page-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

            return false;
        }

        $source = $item->getSource();

        // Sleep for Xms to reduce load on the remote server
        usleep((int) self::$sleep_multiplier * 1000);

        // Extract remote location of File
        $contentFields = $this->getContentFieldsAndSelectors($item, 'File');

        // Default value for Title
        if (empty($contentFields['Filename'])) {
            $contentFields['Filename'] = ['content' => $item->externalId];
        }

        $schema = $source->getSchemaForURL($item->AbsoluteURL, $item->ProcessedMIME);

        if (!$schema) {
            $this->utils->log(" - Couldn't find an import schema for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            $this->utils->log("END file-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            return false;
        }

        $dataType = $schema->DataType;

        if (!$dataType) {
            $this->utils->log(" - DataType for migration schema is empty for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            $this->utils->log("END file-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            throw new \Exception('DataType for migration schema is empty!');
        }

        // Process incoming according to user-selected duplication strategy
        if (!$file = $this->duplicationStrategy($dataType, $item, $source->BaseUrl, $strategy, $parentObject)) {
            $this->utils->log("END file-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            return false;
        }

        // Prepare $file with all the correct properties, ready for writing
        $tmpPath = $contentFields['tmp_path'];

        if (!$file = $this->buildFileProperties($file, $item, $source, $tmpPath)) {
            $this->utils->log("END file-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);
            return false;
        }

        /*
         * File::onAfterWrite() calls File::updateFileSystem() which throws
         * an exception if the same image is attempted to be written.
         * N.b this was probably happening because we weren't versioning files through {@link Upload::load()}
         * and the same filename was being used. This should be fixed now (@see: self::versionFile()).
         */
        try {
            if (!$file->write()) {
                $this->utils->log(" - Not imported (no write): ", $item->AbsoluteURL, $item->ProcessedMIME);
            }
    
            // Remove garbage tmp files if/when left lying around
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
    
            $file->publishSingle();

            // Generate thumbnails
            ImageThumbnailHelper::singleton()->run();
        } catch (\Exception $e) {
            $this->utils->log($e->getMessage(), $item->AbsoluteURL, $item->ProcessedMIME);
        }

        $this->utils->log("END file-transform for: ", $item->AbsoluteURL, $item->ProcessedMIME);

        return StaticSiteTransformResult::create($file, $item->stageChildren());
    }

    /**
     * Build the properties required for a safely saved SilverStripe asset.
     * Attempts to detect and fix bad file-extensions based on the available Mime-Type.
     *
     * @param File $file
     * @param Object $item      Object properties are used to fixup bad-file extensions or filenames with no
     *                            extension but which _do_ have a Mime-Type.
     * @param Object $source    Source...TBC
     * @param string $tmpPath
     * @return mixed (boolean | File)
     */
    public function buildFileProperties(File $file, $item, $source, $tmpPath)
    {
        $url = $item->AbsoluteURL;
        $mime = $item->ProcessedMIME;
        $assetsPath = $this->getDirHierarchy($url);

        /*
         * Run checks on original filename and name it as per default if nothing can be done with it.
         * '.zzz' not in framework/_config/mimetypes.yml and unlikely ever to be found in File, so fails gracefully.
         */
        $dummy = 'unknown.zzz';
        $origFilename = pathinfo($url, PATHINFO_FILENAME);
        $origFilename = (mb_strlen($origFilename) > 0 ? $origFilename : $dummy);

        /*
         * Some assets come through with no file-extension, which confuses SS's File logic
         * and throws errors causing the import to stop dead.
         * Check for this and guess an appropriate file-extension, if possible.
         */
        $oldExt = pathinfo($url, PATHINFO_EXTENSION);
        $extIsValid = in_array($oldExt, $this->getSSExtensions());
        // Only attempt to define and append a new filename ($newExt) if $oldExt is invalid
        $newExt = null;

        if (!$extIsValid && !$newExt = $this->mimeProcessor->ext_to_mime_compare($oldExt, $mime, true)) {
            $this->utils->log(" - WARNING: Bad file-extension: \"$oldExt\". Unable to assign new file-extension (#1) - DISCARDING.", $url, $mime);

            return false;
        } elseif ($newExt) {
            $useExtension = $newExt;
            $logMessagePt1 = "NOTICE: Bad file-extension: \"$oldExt\". Assigned new file-extension: \"$newExt\" based on MimeType.";
            $logMessagePt2 = PHP_EOL."\t - FROM: \"$url\"".PHP_EOL."\t - TO: \"$origFilename.$newExt\"";

            $this->utils->log(' - ' . $logMessagePt1 . $logMessagePt2, '', $mime);
        } else {
            // If $newExt didn't work, check again if $oldExt is invalid and just lose it.
            if (!$extIsValid) {
                $this->utils->log(" - WARNING: Bad file-extension: \"$oldExt\". Unable to assign new file-extension (#2) - DISCARDING.", $url, $mime);

                return false;
            }

            if ($this->mimeProcessor->isBadMimeType($mime)) {
                $this->utils->log(" - WARNING: Bad mime-type: \"$mime\". Unable to assign new file-extension (#3) - DISCARDING.", $url, $mime);

                return false;
            }

            $useExtension = $oldExt;
        }

        $folder = Folder::find_or_make($assetsPath);
        $fileName = sprintf('%s.%s', $origFilename, $useExtension);
        $file->setFromLocalFile($tmpPath, $fileName);
        $file->setFilename($fileName);
        $file->ParentID = $folder->ID;
        $file->StaticSiteContentSourceID = $source->ID;
        $file->StaticSiteURL = $url;
        $file->StaticSiteImportID = $this->getCurrentImportID();

        $this->utils->log(" - NOTICE: \"File-properties built successfully for: ", $url, $mime);

        return $file;
    }

    /**
     * Determine the correct parent directory hierarchy from the imported file's remote-path,
     * such that it is mapped to the appropriate area under the main SilverStripe 'assets' directory.
     *
     * @param string $absolutePath The absolute path of this file on the remote server.
     * @param boolean $full Return absolute path from server's filesystem root
     * @return string The path to append to 'assets' and use as local cache dir.
     */
    public function getDirHierarchy(string $absoluteUrl, bool $full = false): string
    {
        /*
         * Determine the top-level directory under 'assets' under-which this item's
         * dir-hierarchy will be created.
         */
        $parentDir = '';
        $postVars = Controller::curr()->request->postVars();

        if (!empty($postVars['FileMigrationTarget'])) {
            $parentDirData = DataObject::get_by_id(File::class, $postVars['FileMigrationTarget']);
            $parentDir = $parentDirData->Title;
        }

        $replaceUnused = preg_replace("#https?://(www.)?[^/]+#", '', $absoluteUrl);
        $fragments = explode('/', $replaceUnused);
        $filename = pathinfo($absoluteUrl, PATHINFO_FILENAME);
        $path = [];

        foreach ($fragments as $fragment) {
            $dontUse = (!strlen($fragment) || preg_match("#(http|$filename|www\.)+#", $fragment));

            if ($dontUse) {
                continue;
            }

            array_push($path, $fragment);
        }

        $joinedPath = Controller::join_links($parentDir, implode('/', $path));
        $fullPath = ASSETS_PATH . ($joinedPath ? DIRECTORY_SEPARATOR . $joinedPath : '');

        return $full ? $fullPath : $joinedPath;
    }

    /**
     * Borrows logic from Upload::load() to ensure duplicated files get renamed
     * correctly. This therefore allows multiple versions of the same physical image
     * on the filesystem.
     *
     * @param string $relativeFilePath The path to the file relative to the 'assets' dir.
     * @return string $relativeFilePath
     * @throws LogicException
     */
    public function versionFile(string $relativeFilePath): string
    {
        // A while loop provides the ability to continually add further duplicates with the right name
		$base = ASSETS_PATH;

		while(file_exists("$base/$relativeFilePath")) {
            $i = isset($i) ? ($i + 1) : 2;
            $oldFilePath = $relativeFilePath;

            // make sure archives retain valid extensions
            $isTarGz = substr($relativeFilePath, strlen($relativeFilePath) - strlen('.tar.gz')) == '.tar.gz';
            $isTarBz2 = substr($relativeFilePath, strlen($relativeFilePath) - strlen('.tar.bz2')) == '.tar.bz2';

            if ($isTarGz || $isTarBz2) {
                $relativeFilePath = preg_replace('#[0-9]*(\.tar\.[^.]+$)#', $i . "$1", $relativeFilePath);
            } elseif (strpos($relativeFilePath, '.') !== false) {
                $relativeFilePath = preg_replace('#[0-9]*(\.[^.]+$)#', $i . "$1", $relativeFilePath);
            } elseif (strpos($relativeFilePath, '_') !== false) {
                $relativeFilePath = preg_replace('#_([^_]+$)#', '_' . $i, $relativeFilePath);
            } else {
                $relativeFilePath .= '_' . $i;
            }

            // We've tried and failed, so we'll just end-up returning the original, that way we get _something_
            if ($oldFilePath == $relativeFilePath && $i > 2) {
                $this->utils->log(" - Couldn't fix $relativeFilePath with $i attempts in " . __FUNCTION__);
            }
        }

        return $relativeFilePath;
    }
}
