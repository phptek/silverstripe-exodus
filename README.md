# Silverstripe Exodus

## Introduction

Exodus is a content migration tool that roughly follows the ETL standard (**Extract Transform Load**). It will extract content from virtually any website, regardless of its underlying CMS technology and import it as native content objects into a Silverstripe instance.

Exodus crawls and parses the source website's DOM (Extract), normalises any page-URLs (Transform) and imports content as native Silverstrioe objects into your site-tree and assets hierarchy (Load).

Please [See the docs](./docs/en/index.md).

## How it works

Importing a site is a __3__ stage process:

 1. Extract
 2. Transform
 3. Load

The "Extract" phase involves configuring the tool with "schemas" where you tell it how to **extract** content from the source and map it to a Silverstripe class with a URL pattern, a Mime-Type and one or more CSS selectors mapped to fields on the selected class. Expect to spend a decent amount of time analysing your source website-content, tweaking these settings and repeating your crawl and imports until you're happy you've got things just right.

"Transform" is the process of modifying URL patterns found in source systems which are unique to particular systems such as Drupal, Wordpress or Plone for example. This is automatic and occurs with the selection made in the main "URL Processing" selection. This may be trial and error until the crawl process completes.


"Load" is currently denoted as "Import" within the tool (this will change as work is ongoing) and is where the hard work of tweaking your crawl settings pays off and you can import the extracted content into your site-tree.

A list of URLs are fetched and extracted from the site via [PHPCrawl](http://cuab.de/),
and cached in a text file under the assets directory.

Each cached URL corresponds to a page or asset (css, image, pdf etc) that the module
will attempt to import into native SilverStripe objects e.g. `SiteTree` and `File`.

Page content is imported page-by-page using cUrl, and the desired DOM elements
extracted via configurable CSS selectors via [phpQuery](https://github.com/electrolinux/phpquery)
which is leveraged for this purpose.

Please [See the docs](./docs/en/index.md).

## Migration

Please [See the docs](./docs/en/index.md).

## Installation and Setup

This module requires the [PHP Sempahore](https://www.php.net/manual/en/sem.installation.php)
functions to work. (TODO: Still true in php7/8?)

Once that's done, you can use [Composer](http://getcomposer.org) to add the module
to your SilverStripe project:

    #> composer require phptek/silverstripe-exodus

Please see the included [Migration](docs/en/migration.md) document, that describes
exactly how to configure the tool to perform a content migration.

Please [See the docs](./docs/en/index.md).

## License

This code is available under the BSD license, with the exception of the [PHPCrawl](https://github.com/crispy-computing-machine/phpcrawl/) library, bundled with this module which is GPL version 2.
