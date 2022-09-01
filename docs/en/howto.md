# Migration

With the module installed, login to the CMS and observe a new Model Admin menu-item labelled "External Content". Select this and observe a dropdown menu and a 'Create' button. Select the 'Static Site Content Source' optionin the dropdown and select 'Create', you'll see 'New Connector' in the list of Connectors, click it to open.

Fill in the fields, referring to the field-decriptions for context. The Base URL should be of the form e.g. `https://www.example.org`. If the site you wish to import is a MOSS (Microsoft Office Sharepoint Server) site with `/Pages/some-page.aspx` styled URLs, select 'MOSS-style URLs' under URL processing, then select the 'Save' button.

Go to the "Crawl" tab and select "Crawl Site". Leave it running. It will take some time to complete depending on the number of pages and assets in the site being crawled.

## Protips

 1. If you reopen the Connector admin in a different browser (so that it has a different session cookie), you can see the current status of the crawling process.

 2. Ensure you have your browser's developer tools open _before_ you set the crawl off. Occasionally the crawl will die for unknown reasons - usually if your localhost/container has run out of resources - but review your browser's logs which will help in debugging. Note also that there's an import/extraction log available with more information in it:

 ```
 tail -f /tmp/exodus.log
 ```

 3. If the host you're running the module on is behind a proxy, adapt the following:

 **app/_config/config.yml**

```
PhpTek\Exodus\Extract\StaticSiteContentExtractor:
  curl_opts_proxy:
    hostname: my-gateway.co.nz
    port: 1234
```

## Next Steps

Once the crawling is complete (a message will show in the CMS UI), you'll see all the URLs laid out underneath the connector in a tree hierarchy. The URL structure (i.e. where the slashes are) is used to build a hierarchy of URLs. (**TODO** this doesn't work - no hierarchy is displayed in the central CMS pane).

Now it's time to write some CSS selectors to query different pieces of content for the import. Go to the "Main" tab and selected the "Add Schema" button.

Fill out the fields as follows:

* Priority: 1
* URLs applied to: .*
* DataType: Page

Now select the "Add Rule" button and immediately select "Save" - this allows you to select from the "Field Name" dropdown menu.

* Specify a field to import into - usually "Title" or "Content"
* Specify a CSS selector e.g. `body#content h1` or `#body#content section.main` respectively
* If you have different CSS selectors for different pages, create multiple Import Rules. Just note that the first one rule that actually returns content will be used.
  
Open sample pages in the tree on the left for review. Here you can assess whether or not the Import Rules have worked as expected. If they don't work, debug them, modify your import rules and schema then re-try.

Using simple CSS selectors you can control the parts of source page that are mapped to a particular field within the `SiteTree` class.

Select a base page to import onto. Sometimes it's helpful to create an "imported content" parent page in the Pages section of the CMS first.

Select the "Start Importing" button (See Protip #2, above). This will also take a long while and doesn't have a robust resume functionality. That's on the to-do list.

That's it! There are quite a few steps but it's easier than copy & pasting all those pages!

### Schema

_Schema_ is the name given to a collection of rules that instruct the module how a crawled website should have its markup formatted and stored as/in Silverstripe objects during an import.

Each rule in a schema is predicated on a CSS selector which defines the exact DOM fragment on a specific page of the crawled site to transform, and the respective DataObject-subclass field within Silverstripe where this transformed content should be stored.

#### Schema Urls

The schema field "URLs Applied to" is where you define regular expressions to match urls from the legacy site to the imported DataTypes in the new site. Each url is matched against the absolute urls crawled from the legacy site, so you'll need to include the protocol _and_ domain in your Url patterns to make them
absolute as well, e.g. "https://www.legacysite.com/news/*".

The actual regular expression is located in `src/Model/StaticSiteContentSource.php` in the function `schemaCanParseURL()`:

```
if (preg_match("|^$appliesTo|", $url) == 1) {
...
}
```
	
##### Protip

* For help with regular expressions, use an online service like [Rubular.com](http://rubular.com) or [PHPLiveRegex.com](http://phpliveregex.com), both of which allow "live" regular expression editing.

#### Schema Priority

Priority order of your schemas is important, the `Applies To` Url patterns are matched against the imported urls in priority order until the first matching schema is found.

This means you need to order your schemas with the most specific patterns first (e.g. `CustomNewsPage`, `NewsPage`, `NewsHolder` etc), then gradually filter down the priority order to the default catch-all patterns for `Page`, `Image` and `File`.

The default catch-all patterns are:

```
+-----------------+-----------+--------------------------------------+
| Url Applies To  | Data Type | Mime-types                           |
+-----------------+-----------+--------------------------------------+
| .*              | Page      | text/html                            |
| .*              | Image     | image/(png|jpeg|gif)                 |
| .*              | File      | application/(vnd.*|msword|pdf|xml)   |
+-----------------+-----------+--------------------------------------+
```

#### Example Rules:

__Notes:__

* There is an (example SQL dump)[docs/en/example.sql] (MySQL/MariaDB only) included to get you up and running quickly.
* The example below is based on your import using a subclass of `SiteTree`

##### Title

This rule takes the content of the crawled-site's &lt;h1&gt; element, imports it
into the `SiteTree.Title` field which forms your imported page's &lt;title&gt; element.

* __Field Name:__ `Title`
* __CSS Selector:__ `h1`
* __Exclude CSSSelector:__ [Optional]
* __Element attribute:__ [Optional]
* __Convert to plain text:__ Check this to remove all markup found in the crawled site
* __Schema:__ Select "Page" or your custom SilverSstripe DataObject to import content into

##### MenuTitle

This rule takes the content of the crawled-site's &lt;h1&gt; element, imports it into
the `SiteTree.MenuTitle` field. This is used in the CMS' SiteTree list.

* __Field Name:__ `MenuTitle`
* __CSS Selector:__ `h1`
* __Exclude CSSSelector:__ [Optional]
* __Element attribute:__ [Optional]
* __Convert to plain text:__ Check this box to remove all markup found in the crawled site
* __Schema:__ Select "Page" or your custom SilverStripe DataObject to import content into

##### Content

This rule takes the content of the crawled-site's main body content (excluding any &lt;h1&gt; elements) - in this example we pretend it's all wrapped in a div#content element. This will then form the content that is used in the `SiteTree.Content` field.

* __Field Name:__ `Content`
* __CSS Selector:__ `div#content`
* __Exclude CSSSelector:__ `h1`
* __Element attribute:__ [Optional]
* __Convert to plain text:__ Leave this unchecked, you'll probably want to keep all the crawled site's markup as it's being imported into an HTMLText fieldtype, eventually editable in the CMS via the WYSIWYG editor
* __Schema:__ Select "Page" or your custom Silverstripe DataObject to import content into

#### Meta - Description

This rule will collect the contents of a crawled-page's &lt;meta&gt; (description element and imports it into the `SiteTree.MetaDescription` field. You can obviously adapt this to suit other &lt;meta&gt; elements you wish to import.

* __Field Name:__ `MetaDescription`
* __CSS Selector:__ `meta[name=description]`
* __Exclude CSSSelector:__
* __Element attribute:__ `value`
* __Convert to plain text:__ Check this box to remove all markup found in the crawled site (v.unlikely!)
* __Schema:__ Select "Page" or your custom SilverStripe DataObject to import content into

## Migration Post-Processing

After the import has completed, the content may* still contain urls and asset-paths
that reference static urls from the impprted, legacy site.

`*` If you selected `Automatically run link-rewrite task` all links will have been automatically converted for you, see below for more information.

### Static Site Link Rewriting

There is a built-in `BuildTask` subclass which will modify urls in imported content, replacing the `src` & `href` attributes of links, images and files with Silverstripe CMS shortcodes that reference the imported assets and page IDs.

If you check the box `Automatically run link-rewrite task` under the "Main" tab in the CMS' UI, this will happen seamlessly for you. This should probably just be the default...

See: 

```
src/Task/StaticSiteRewriteLinksTask.php
```

For hints on usage, run the task from the command-lne without any arguments.

#### Notes

If enabled in the `external-content` "Import" section, this task can actually be run automatically once the import itself has completed. This is useful for Silverstripe setups where you may not have shell access to the server to run `BuildTasks`.

There is a comprehensive CMS report "Imported links rewrite report" which is available after each import. You can use the data to analyse your imports and rewritten links, to help you tweak your crawl and import rules and help to point out exactly what's failing and how you might fix it manually if need be.
