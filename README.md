# Silverstripe Exodus

## Introduction

Exodus is a content migration tool that roughly follows the ETL standard ([**Extract. Transform. Load.**](https://en.wikipedia.org/wiki/Extract,_transform,_load)). It will extract content from virtually any website, regardless of its underlying CMS technology and import it as native content objects into a Silverstripe instance.

**Exodus** will first crawl the source website's DOM (Extract) and cache them to the local filesystem. The next step is to normalise any page-URLs (Transform) and run a site-scrape which imports content as native Silverstripe objects into your site-tree and assets hierarchy (Load).

Please [See the docs](./docs/en/index.md).

## How it works

"Extract" is where you configure "schemas" which tells the module how to **extract** content from the source site and map it to a Silverstripe class with a URL pattern, a Mime-Type and one or more CSS selectors mapped to fields on the selected class. Expect to spend a decent amount of time analysing the source website's content, tweaking these settings and repeating your crawls and imports until you're happy that you've got things just about right.

"Transform" is the process of modifying URL patterns which are unique to particular systems such as Drupal, Wordpress or Plone. This is automatic and occurs with the selection made in the main "URL Processing" selection. This may be trial and error until the crawl process completes.

"Load" is currently denoted as "Import" within the tool and is where the hard work of tweaking your crawl settings pays off and you can import the extracted content into your site-tree.

A list of URLs are fetched and extracted from the site via [PHPCrawl](http://cuab.de/), and cached in a text file under the assets directory.

Each cached URL corresponds to a page or asset (css, image, pdf etc) that the module will attempt to import into native SilverStripe objects e.g. `SiteTree` and `File`.

Page content is imported page-by-page using cUrl, and the desired DOM elements extracted via configurable CSS selectors via [phpQuery](https://github.com/electrolinux/phpquery) which is leveraged for this purpose.

Please [See the docs](./docs/en/index.md).

## Migration

Please [See the docs](./docs/en/index.md).

## Installation and Setup

This module requires the [PHP Sempahore](https://www.php.net/manual/en/sem.installation.php) functions to work. (TODO: Still true in php7/8?)

Once that's done, you can use [Composer](http://getcomposer.org) to add the module to your SilverStripe project:

```
composer require phptek/silverstripe-exodus
```

Please see the included [Migration](docs/en/howto.md) document, that describes exactly how to configure the tool to perform a content migration.

Please [See the docs](./docs/en/index.md).

## License

This code is available under the BSD license, with the exception of the [PHPCrawl](https://github.com/crispy-computing-machine/phpcrawl/) library, bundled with this module which is GPL version 2.
