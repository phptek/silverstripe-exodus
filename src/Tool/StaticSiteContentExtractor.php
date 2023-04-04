<?php

namespace PhpTek\Exodus\Tool;

use phpQuery;
use DOMDocument;
use DOMElement;
use PhpTek\Exodus\Tool\StaticSiteMimeProcessor;
use PhpTek\Exodus\Tool\StaticSiteUtils;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Convert;
use SilverStripe\Core\TempFolder;

/**
 * This tool uses a combination of cURL and phpQuery to extract content from a URL.
 *
 * The URL is first downloaded using cURL, and then passed into phpQuery for processing.
 * Given a set of fieldnames and CSS selectors corresponding to them, a map of content
 * fields will be returned.
 *
 * If the URL represents a file-based Mime-Type, a Silverstripe `File` object is created and the
 * physical file it represents can then be post-processed and saved to the dstabase and F/S.
 *
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */
class StaticSiteContentExtractor
{
    use Injectable;
    use Configurable;

    /**
     *
     * @var string
     */
    protected $url = null;

    /**
     *
     * @var string
     */
    protected $mime = null;

    /**
     * This is an HTML page's source markup.
     * 
     * @var string
     */
    protected $content = null;

    /**
     *
     * @var phpQueryObject
     */
    protected $phpQuery = null;

    /**
     *
     * @var string
     */
    protected $tmpFileName = '';

    /**
     * "Caches" the mime-processor for use throughout
     *
     * @var StaticSiteMimeProcessor
     */
    protected $mimeProcessor;

    /**
     * Holds the StaticSiteUtils object on construct
     *
     * @var Object
     */
    protected $utils;

    /**
     * Create a StaticSiteContentExtractor for a single URL/.
     *
     * @param string $url The absolute URL to extract content from
     * @param string $mime The Mime-Type
     * @param string $content (Optional. Useful only for crude tests that avoid rigging-up a URL to parse)
     * @return void
     * @throws \Exception
     */
    public function __construct($url, $mime, $content = null)
    {
        $this->url = $url;
        $this->mime = $mime;
        $this->content = $content;
        $this->mimeProcessor = singleton(StaticSiteMimeProcessor::class);
        $this->utils = singleton(StaticSiteUtils::class);

        $this->utils->log(sprintf('Begin extraction for URL: %s and Mime: %s', $this->url, $this->mime));
    }

    /**
     * Extract content for map of field => css-selector pairs
     *
     * @param  array $selectorMap A map of field name => css-selector
     * @param  StaticSiteContentItem $item The item to extract
     * @return array Map of fieldname => ['selector' => selector, 'content' => field content]
     */
    public function extractMapAndSelectors($selectorMap, $item): array
    {
        if (!$this->phpQuery) {
            $this->fetchContent();
        }

        $output = [];
        foreach ($selectorMap as $fieldName => $extractionRules) {
            if (!is_array($extractionRules)) {
                $extractionRules = [$extractionRules];
            }

            foreach ($extractionRules as $extractionRule) {
                $content = '';

                if (!is_array($extractionRule)) {
                    $extractionRule = ['selector' => $extractionRule];
                }

                if ($this->isMimeHTML()) {
                    $cssSelector = $extractionRule['selector'] ?? '';
                    $attribute = $extractionRule['attribute'] ?? '';
                    $outerHTML = $extractionRule['outerhtml'] ?? false;
                    $content = $this->extractField($cssSelector, $attribute, $outerHTML);
                } elseif ($this->isMimeFileOrImage()) {
                    $content = $item->externalId;
                }

                if (!$content) {
                    continue;
                }

                // Further processing
                if ($this->isMimeHTML()) {
                    $content = $this->excludeContent($extractionRule['excludeselectors'], $extractionRule['selector'], $content);
                }

                if (!$content) {
                    continue;
                }

                if (!empty($extractionRule['plaintext'])) {
                    $content = Convert::html2raw($content);
                }

                // We found a match, select that one and ignore any other selectors
                $output[$fieldName] = $extractionRule;
                $output[$fieldName]['content'] = $content;
                break;
            }
        }

        return $output;
    }

    /**
     * Extract content for a single css selector
     *
     * @param  string $cssSelector The CSS selector for which to extract content.
     * @param  string $attribute   If set, the value will be from this HTML attribute.
     * @param  bool   $outherHTML  Should we return the full HTML markup of the whole field?
     * @return string The content for the passed $cssSelector.
     */
    public function extractField(string $cssSelector, string $attribute = '', bool $outerHTML = false): string
    {
        if (!$this->phpQuery) {
            // Sets $this->phpQuery - weird pattern 
            $this->fetchContent();
        }

        $elements = $this->phpQuery[$cssSelector];

        // @todo temporary workaround for File objects
        if (!$elements) {
            return '';
        }

        // just return the inner HTML for this node
        if (!$outerHTML || !$attribute) {
            return trim($elements->html());
        }

        $result = '';
        foreach ($elements as $element) {
            // Get the full html for this element
            if ($outerHTML) {
                $result .= $this->getOuterHTML($element);
            }
            // Get the value of an attribute
            elseif ($attribute && trim($element->getAttribute($attribute))) {
                $result .= ($element->getAttribute($attribute)) . PHP_EOL;
            }
        }

        return trim($result);
    }

    /**
     * Strip away content from $content that matches one or many css selectors.
     *
     * @param array $excludeSelectors
     * @param string $parentSelector
     * @param string $content
     * @return string
     */
    protected function excludeContent($excludeSelectors, $parentSelector, $content)
    {
        if (!$excludeSelectors) {
            return $content;
        }

        foreach ($excludeSelectors as $excludeSelector) {
            if (!trim($excludeSelector)) {
                continue;
            }
            $element = $this->phpQuery[$parentSelector.' '.$excludeSelector];
            if ($element) {
                $remove = $element->htmlOuter();
                $content = str_replace($remove, '', $content);
                $this->utils->log(' - Excluded content from "' . $parentSelector . ' ' . $excludeSelector . '"');
            }
        }
        return $content;
    }

    /**
     * Get the full HTML of the element and its children
     *
     * @param DOMElement $element
     * @return string
     */
    protected function getOuterHTML(DOMElement $element)
    {
        $doc = new DOMDocument();
        $doc->formatOutput = false;
        $doc->preserveWhiteSpace = true;
        $doc->substituteEntities = false;
        $doc->appendChild($doc->importNode($element, true));
        return $doc->saveHTML();
    }

    /**
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Fetch the content, initialise $this->content and $this->phpQuery.
     * Initialise the latter only if an appropriate mime-type matches.
     *
     * @return void
     * @todo deal-to defaults when $this->mime isn't matched.
     */
    protected function fetchContent()
    {
        $this->utils->log(" - Fetching {$this->url} ({$this->mime})");

        // Set some proxy options for phpCrawler
        $curlOpts = singleton(StaticSiteUtils::class)->defineProxyOpts(!Director::isDev());
        $response = $this->curlRequest($this->url, "GET", null, null, $curlOpts);

        if ($response == 'file') {
            // Just stop here for files & images
            return;
        }

        $this->content = $response->getBody();

        // Clean up the content so phpQuery doesn't bork
        $this->prepareContent();
        $this->phpQuery = phpQuery::newDocument($this->content);
    }

    /**
     * Use cURL to request a URL, and return a HTTPResponse object (`SiteTree`) or write curl output directly to a tmp file
     * ready for uploading to SilverStripe via Upload#load() (`File` and `Image`)
     *
     * @todo Refactor using Guzzle
     * @param string $url
     * @param string $method
     * @param string $data
     * @param string $headers
     * @param array $curlOptions
     * @return boolean | HTTPResponse
     * @todo Add checks when fetching multi Mb images to ignore anything over 2Mb??
     */
    protected function curlRequest($url, $method, $data = null, $headers = null, $curlOptions = [])
    {
        $this->utils->log(" - CURL START: {$this->url} ({$this->mime})");

        $ch = curl_init();
        $timeout = 10;

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config()->get('user_agent'));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Add fields to POST and PUT requests
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($method == 'PUT') {
            $put = fopen("php://temp", 'r+');
            fwrite($put, $data);
            fseek($put, 0);

            curl_setopt($ch, CURLOPT_PUT, 1);
            curl_setopt($ch, CURLOPT_INFILE, $put);
            curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));
        }

        // Follow redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Set any custom options passed to the request() function
        curl_setopt_array($ch, $curlOptions);

        // Run request
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // See: http://forums.devshed.com/php-development-5/curlopt-timeout-option-for-curl-calls-isn-t-being-obeyed-605642.html
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);		// No. seconds to wait while trying to connect.

        // Deal to files, write to them directly and then return
        if ($this->mimeProcessor->isOfFileOrImage($this->mime)) {
            $tmp_name = @tempnam(TempFolder::getTempFolder('/') . '/' . rand(), 'tmp');
            $fp = fopen($tmp_name, 'w+');
            curl_setopt($ch, CURLOPT_HEADER, 0); // We do not want _any_ header info, it corrupts the file data
            curl_setopt($ch, CURLOPT_FILE, $fp); // write curl response directly to file, no messing about
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);

            $this->setTmpFileName($tmp_name); // Set a tmp filename

            return 'file';
        }

        $fullResponseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        @list($responseHeaders, $responseBody) = explode("\n\n", str_replace("\r", "", $fullResponseBody), 2);

        if (preg_match("#^HTTP/1.1 100#", $responseHeaders)) {
            list($responseHeaders, $responseBody) = explode("\n\n", str_replace("\r", "", $responseBody), 2);
        }

        $responseHeaders = explode("\n", trim($responseHeaders));
        // Shift off the HTTP response code
        array_shift($responseHeaders);

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError !== '' || $statusCode == 0) {
            $this->utils->log(" - CURL ERROR: Error: $curlError Status: $statusCode");
            $statusCode = 500;
        }

        $response = HTTPResponse::create($responseBody, $statusCode);

        foreach ($responseHeaders as $headerLine) {
            if (strpos($headerLine, ":") !== false) {
                list($headerName, $headerVal) = explode(":", $headerLine, 2);
                $response->addHeader(trim($headerName), trim($headerVal));
            }
        }

        $this->utils->log(" - CURL END: {$this->url}. Status: $statusCode. ({$this->mime})");
        return $response;
    }

    /**
     *
     * @param string $tmp
     * @return void
     */
    public function setTmpFileName($tmp)
    {
        $this->tmpFileName = $tmp;
    }

    /**
     *
     * @return string
     */
    public function getTmpFileName()
    {
        return $this->tmpFileName;
    }

    /**
     * @see {@link StaticSiteMimeProcessor}
     * @return boolean
     */
    public function isMimeHTML()
    {
        return $this->mimeProcessor->isOfHTML($this->mime);
    }

    /**
     * @see {@link StaticSiteMimeProcessor}
     * @return boolean
     */
    public function isMimeFile()
    {
        return $this->mimeProcessor->isOfFile($this->mime);
    }

    /**
     * @see {@link StaticSiteMimeProcessor}
     * @return boolean
     */
    public function isMimeImage()
    {
        return $this->mimeProcessor->isOfImage($this->mime);
    }

    /**
     * @see {@link StaticSiteMimeProcessor}
     * @return boolean
     */
    public function isMimeFileOrImage()
    {
        return $this->mimeProcessor->isOfFileOrImage($this->mime);
    }

    /**
     * Pre-process the content so phpQuery can parse it without violently barfing.
     *
     * @return void
     */
    public function prepareContent(): void
    {
        // Trim it
        $this->content = trim($this->content ?? '');

        // Ensure the content begins with the 'html' tag
        if (stripos($this->content, '<html') === false) {
            $this->content = '<html>' . $this->content;
            $this->utils->log('Warning: content was missing opening "<html>" tag.');
        }
    }
}
