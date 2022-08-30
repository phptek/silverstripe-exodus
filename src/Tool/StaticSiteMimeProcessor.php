<?php

namespace PhpTek\Exodus\Tool;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Assets\File;
use SilverStripe\Control\HTTP;
use SilverStripe\Core\Config\Config;

/**
 *
 * Utility class for Mime-Type processing.
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteMimeProcessor
{
    use Injectable;
    use Configurable;

    /**
     *
     * @var array internal "cache" of mime-types
     */
    public $mimeTypes;

    /**
     *
     * @return void
     */
    public function __construct()
    {
        $args = func_get_args();

        if (isset($args[0])) {
            $mimeTypes = $args[0];

            $this->setMimes($mimeTypes);
        }
    }

    /**
     * Based on one of three SilverStripe core classes, returns an array of suitable mime-types
     * from SilverStripe config.
     * Used to represent matching content or all associated mimes if no type is passed.
     *
     * @param $nativeType one of: SiteTree, File, Image
     * @return array
     */
    public static function get_mime_for_ss_type($nativeType = null): array
    {
        $httpMimeTypes = Config::inst()->get(HTTP::class, 'MimeTypes');
        $ssTypeMimeMap = self::ss_type_to_suffix_map();

        // This config file not guaranteed to always be present
        if (!$httpMimeTypes or !is_array($httpMimeTypes)) {
            return [];
        }

        $mimes = [
            'sitetree' => [],
            'file' => [],
            'image' => [],
        ];

        // Only support specific classes
        if ($nativeType && !in_array(strtolower($nativeType), array_keys($mimes))) {
            return [];
        }

        foreach ($httpMimeTypes as $mimeKey => $mimeType) {
            // SiteTree
            if (in_array($mimeKey, $ssTypeMimeMap['sitetree'])) {
                $mimes['sitetree'][] = $mimeType;
            }

            // File
            if (in_array($mimeKey, $ssTypeMimeMap['file'])) {
                // Separate treatment for csv which can either be text/plain (official) or text/csv
                $mimeType = ($mimeKey == 'csv' ? 'text/csv' : $mimeType);
                $mimes['file'][] = $mimeType;
            }

            // Image
            if (in_array($mimeKey, $ssTypeMimeMap['image'])) {
                $mimes['image'][] = $mimeType;
            }
        }

        // Not included in Silverstripe's file-based Mime-Types
        array_push($mimes['file'], 'text/plain');

        // Included in Silverstripe's file-based Mime-Types which we don't want
        $mimes['file'] = array_filter($mimes['file'], function($k, $v) {
            return stristr($k, 'htm') ? false : true;
        }, ARRAY_FILTER_USE_BOTH);

        if ($nativeType) {
            $nativeType = strtolower($nativeType);

            if (isset($mimes[$nativeType])) {
                return array_unique($mimes[$nativeType]);
            }
        }

        return $mimes;
    }

    /**
     * Return a mapping of SS types (File, SiteTree etc) to suitable file-extensions
     * out of the File class.
     *
     * @param string $nativeType
     * @return array
     */
    public static function ss_type_to_suffix_map($nativeType = null)
    {
        $mimeCategories = File::config()->get('app_categories');

        /*
         * Imported files and images are going to be passed through to Upload#load()
         * and checked aginst File::$app_categories so use this method to
         * filter calls to DataObject#validate()
         */

        // Get SilverStripe supported SiteTree-ish mime categories
        $mimeKeysForSiteTree = ['html', 'htm', 'xhtml'];

        // Get SilverStripe supported File-ish mime categories
        // File contains values of $mimeKeysForSiteTree which we don't want
        $mimeKeysForFile = $mimeCategories['document'];

        // Get SilverStripe supported Image-ish mime categories
        $mimeKeysForImage = $mimeCategories['image'];
        $map = [
            'sitetree'	=> $mimeKeysForSiteTree,
            'file'		=> $mimeKeysForFile,
            'image'		=> $mimeKeysForImage
        ];

        if ($nativeType) {
            $nativeType = strtolower($nativeType);

            // Only support specific classes
            if (!in_array($nativeType, array_keys($mimeCategories))) {
                return false;
            }

            return $map[$nativeType];
        }

        return $map;
    }

    /**
     * Compares a file-extension with a mime type. Returns true if the passed extension
     * matches the passed mime.
     *
     * @param string $ext The file extension to compare e.g. ".doc"
     * @param string $mime The Mime-Type to compare e.g. application/msword
     * @param boolean $fix Whether or not to try and "fix" borked file-extensions
     * coming through from third-parties.
     *
     * - If true, the matched extension is returned (if found, otherwise false) instead of boolean false
     * - This is a pretty sketchy way of doing things and relies on the file-extension string comprising the mime-type
     * - e.g. "pdf" can be found in "application/pdf" but "doc" cannot be found in "application/msword"
     *
     * @return mixed boolean or string $ext | $coreExt if the $fix param is set to true, no extra processing is required
     */
    public static function ext_to_mime_compare($ext, $mime, $fix = false)
    {
        $httpMimeTypes = HTTP::config()->get('MimeTypes');
        $mimeCategories = File::config()->get('app_categories');
        list($ext, $mime) = [strtolower($ext), strtolower($mime)];

        $notAuthoratative = !isset($httpMimeTypes[$ext]);					// We've found ourselves a weird extension
        $notMatch = (!$notAuthoratative && $httpMimeTypes[$ext] !== $mime);	// No match found for passed extension in our ext=>mime mapping from config

        if ($notAuthoratative || $notMatch) {
            if (!$fix) {
                return false;
            }

            // Attempt to "fix" broken or badly encoded file-extensions by guessing what it should be, based on $mime
            $coreExts = array_merge($mimeCategories['document'], $mimeCategories['image']);
            foreach ($coreExts as $coreExt) {
                // Make sure we check the correct category so we don't find a match for ms-excel in the image \File category (.cel) !!
                $isFile = in_array($coreExt, $mimeCategories['document']) && singleton(__CLASS__)->isOfFile($mime);		// dirty
                $isImge = in_array($coreExt, $mimeCategories['image']) && singleton(__CLASS__)->isOfImage($mime);	// more dirt

                if (($isFile || $isImge) && stristr($mime, $coreExt) !== false) {
                    // "Manually" force "jpg" as the file-suffix to be returned
                    return $coreExt == 'jpeg' ? 'jpg' : $coreExt;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Post-proces user-inputted mime-types. Allows space, comma or newline
     * delimited mime-types input into a TextareaField
     *
     * @param string $mimeTypes
     * @return array - returns an array of mimetypes
     */
    public static function get_mimetypes_from_text($mimeTypes)
    {
        $mimes = preg_split("#[\r\n\s,]+#", trim($mimeTypes));
        $_mimes = [];
        foreach ($mimes as $mimeType) {
            // clean 'em up a little
            $_mimes[] = self::cleanse($mimeType);
        }
        return $_mimes;
    }

    /**
     * Simple cleanup utility
     *
     * @param string $mimeType
     * @return string
     */
    public static function cleanse($mimeType): string
    {
        if (!$mimeType) {
            return '';
        }

        return strtolower(trim($mimeType));
    }

    /**
     * Takes an array of mime-type strings and simply returns true after the first Image-ish mime-type is found
     *
     * @param mixed $mimeTypes
     * @return boolean
     */
    public function isOfImage($mimeTypes): bool
    {
        if (!is_array($mimeTypes)) {
            $mimeTypes = [self::cleanse($mimeTypes)];
        }

        foreach ($mimeTypes as $mime) {
            $imgMime = self::get_mime_for_ss_type('image');

            if ($imgMime && in_array($mime, $imgMime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes an array of mime-type strings and simply returns true after the first File-ish mime-type is found
     *
     * @param mixed $mimeTypes
     * @return boolean
     */
    public function isOfFile($mimeTypes): bool
    {
        if (!is_array($mimeTypes)) {
            $mimeTypes = [self::cleanse($mimeTypes)];
        }

        foreach ($mimeTypes as $mime) {
            $fileMime = self::get_mime_for_ss_type('file');

            if ($fileMime && in_array($mime, $fileMime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes an array of mime-type strings and simply returns true after the first SiteTree-ish mime-type is found
     *
     * @param mixed $mimeTypes
     * @return boolean
     */
    public function isOfHtml($mimeTypes): bool
    {
        if (!is_array($mimeTypes)) {
            $mimeTypes = [self::cleanse($mimeTypes)];
        }

        foreach ($mimeTypes as $mime) {
            $htmlMime = self::get_mime_for_ss_type('sitetree');

            if ($htmlMime && in_array($mime, $htmlMime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple "shortcut" to isOfFile() and isOfImage()
     *
     * @param mixed $mimeTypes
     * @return boolean
     */
    public function isOfFileOrImage($mimeTypes): bool
    {
        if (!is_array($mimeTypes)) {
            $mimeTypes = [self::cleanse($mimeTypes)];
        }

        if ($this->isOfFile($mimeTypes) || $this->isOfImage($mimeTypes)) {
            return true;
        }

        return false;
    }

    /**
     * Ascertain passed $mime is not something we can do anything useful with
     *
     * @param string $mime
     * @return boolean
     */
    public function isBadMimeType($mime): bool
    {
        return (!$this->isOfFileOrImage($mime) && !$this->isOfHtml($mime));
    }

    /*
     *
     * Getters & Setters
     * -----------------
     *
     */

    /**
     *
     * @param array $mimes
     * @return void
     */
    public function setMimes($mimeTypes)
    {
        $this->mimeTypes = $mimeTypes;
    }

    /**
     *
     * @return array
     */
    public function getMimes()
    {
        return $this->mimeTypes;
    }
}
