# Silverstripe Exodus

## Introduction

This module allows you to extract content from another website in an ETL fashion (Extract Transform Load).

It will crawl a source website, parse its DOM structure and transform it into native Silverstripe objects, saving
them into a target Silverstripe install as though they had been created
via the Silverstripe CMS itself.

Although this has the disadvantage of leaving it unable to extract any information
or structure that _isn't_ represented in the site's markup, it means no special access
or reliance on particular back-end systems is required. This makes the module suited
for legacy and experimental site-imports, as well as connections to websites generated
by obscure CMS's.

## How it works

Importing a site is a __3__ stage process:

 1. Extract
 2. Transform
 3. Load

The "Extract" phase involves configuring the tool with "schemas" where you tell it how to **extract** content from the source and map it to a Silverstripe class with a URL pattern, a Mime-Type and one or more CSS selectors mapped to fields on the selected class. Expect to spend a decent amount of time analysing your source website-content and tweaking these settings.

"Transform" is the process of modifying URL patterns found in source systems which are unique to particular systems such as Drupal, Wordpress or Plone for example. This is automatic and occurs with the selection made in the main "URL Processing" selection. This may be trial and error until the crawl process completes.

**Note:** 

* _Developers_ keep an eye on your browser's developer-tools' "Network" tab. Not all errors are reported directly in Silverstripe's UI. Please [report any issues you find](https://github.com/phptek/silverstripe-exodus/issues).  
* You may need to re-run a crawl if the selected URL Processing option fails with an on-screen error message. Each option is designed for websites with fairly specific features and URL patterns and may return prematurely.

"Load" is currently denoted as "Import" within the tool (this will change as work is ongoing) and is where the hard work of tweaking your crawl settings pays off and you can import the extracted content into your site-tree.

A list of URLs are fetched and extracted from the site via [PHPCrawl](http://cuab.de/),
and cached in a text file under the assets directory.

Each cached URL corresponds to a page or asset (css, image, pdf etc) that the module
will attempt to import into native SilverStripe objects e.g. `SiteTree` and `File`.

Page content is imported page-by-page using cUrl, and the desired DOM elements
extracted via configurable CSS selectors via [phpQuery](https://github.com/electrolinux/phpquery)
which is leveraged for this purpose.

## Migration

See the included [migration documentation](docs/en/migration.md) for detailed
instruction on migrating a legacy site into SilverStripe using the module.

## Gotchas

1. If you know the site to be crawled will redirect, use the redirected URL as the value of the "Base URL" field.

## Installation and Setup

This module requires the [PHP Sempahore](https://www.php.net/manual/en/sem.installation.php)
functions to work. (TODO: Still true in php7/8?)

Once that's done, you can use [Composer](http://getcomposer.org) to add the module
to your SilverStripe project:

    #> composer require phptek/silverstripe-exodus

Please see the included [Migration](docs/en/migration.md) document, that describes
exactly how to configure the tool to perform a content migration.

**Notes**

* If using php-fpm, you may need to tweak the settings found in `www.conf`. Contrary to popular belief, the default `pm = dynamic` may not suffice. In (limited) testing we used `pm = static` with `pm.max_children = 25` as opposed to the default `10` which helped crawling a ~250 page site. Tweaking php-fpm gives your application more system resources to call upon during larger site-crawls.

## License

This code is available under the BSD license, with the exception of the [PHPCrawl](https://github.com/crispy-computing-machine/phpcrawl/)
library, bundled with this module which is GPL version 2.
