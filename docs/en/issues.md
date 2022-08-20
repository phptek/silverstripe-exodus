# ToDo

## StaticSiteConnector Module Issues (in order of severity/importance)

* BUG: Can only crawl VHosts. Websites located on a subdirectory e.g. http://localhost/mysite are only partially crawled.
* BUG: Lists of crawled URLs from large crawls (1000+ pages), slow down the CMS considerably. Suggest show only partial tree under each "Connector" (or optimise existing and problematic CMS JS)
* BUG: For MOSS sites: Some Urls are cached but not imported.
* BUG: For MOSS sites: Some Urls are being cached with brackets, even though the MOSS processor rips them out
* BUG: For CWP sites, import and crawl logs may get quite large. Solutions:
 * Disable file-based logging on CWP sites
 * Truncate logs when "clear all import meta-data" is checked in the CMS' UI
 * Automatically truncate logs when a new import or crawl is started
* Selecting a folder to import scraped-assets into that isn't writeable, causes an error and for imports to stop.
 * See: StaticSiteFileTransformer::buildFileProperties() in `$parentFolder = Folder::find_or_make($path)`
 * Real reason is mkdir() in Filesystem::makeFolder()
* TASK: The CMS needs to know what to display in its SiteTree, what happens if there are no `Title` or `MenuTitle` PHPQuery CSS schema mappings?
 * May need to add validation to ensure at a minimum `Content` and `Title` are used.
* TASK: Is StaticSiteCrawlURLsTask needed anymore?
* TASK: Translation: Ensure all messages are rendered through _t()
* TASK: Add new filter expression as per `FileNameFilter` to module _config instead of using str_replace() in StaticSiteFIleTransformer::buildFileProperties()
* ENHANCEMENT: Append message: "Link rewrite task was run automatically. [View failed URL rewrite report]" to "successful import" message.
* ENHANCEMENT: In addition to the "Number of URLs" total under the "Crawl" tab, modify to show a list of totals for each mime-type or SS type (e.g. SiteTree)
* ENHANCEMENT: Add schema export feature for use between SilverStripe installs e.g. CWP
* ENHANCEMENT: Use a ListBoxField on StaticSiteContentSource to display mime-types with data taken from framework/_config/mimetypes.yml
 * This makes it less onerous on the user to know about Mime-Types
 * Menu should change to reflect selection made in the "DataType" field
 * Logic should check for non-presence of the above YML file, and default to TextareaField and manual-input

## External Content Module Issues:

* BUG: Fix the UI under the "Import" tab to store saved values. Currently you will lose your changes if you move away from the "Import" tab and then go back to it.
* [PR#17] ENHANCEMENT: Add a default to "Select how duplicate items should be handled" radio buttons field
* Logic found in StaticSiteTransformResult class should really exist in the external-content module itself.
* TASK: Selecting "external content" in the CMS for the first time, shows nothing in the main pane. Show a default connector (e.g. the first) by default.
 * See:	"extended-default-selection" branch for a solution.
