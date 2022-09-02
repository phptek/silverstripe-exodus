# Silverstripe Exodus

[![CI](https://github.com/phptek/silverstripe-exodus/actions/workflows/ci.yml/badge.svg)](https://github.com/phptek/silverstripe-exodus/actions/workflows/ci.yml)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/phptek/silverstripe-exodus/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/phptek/silverstripe-exodus/?branch=master)
[![License](https://poser.pugx.org/phptek/silverstripe-exodus/license.svg)](https://github.com/phptek/silverstripe-exodus/blob/master/LICENSE.md)

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

## History

This module was originally written in 2012 by then Silverstripe Ltd CEO [Sam Minnee](https://github.com/sminnee/) and was known as the "Static Site Connector" module. It was used successfully on dozens of occassions to import content for new Silverstripe projects being built by the company at that time and was subsequently improved upon over the years by other Silverstripe employees.

Around 2015-2016 the module was archived by Sam and subsequently picked-up and improved by [Russell Michell](https://github.com/phptek/).

In 2022 Russell saw a need for the tool again for an upcoming gig and modified it once again to work with Silverstripe v4.

## Contributers

In order of no. commits:

* [Russell Michell](https://github.com/phptek/)
* [Sam Minnee](https://github.com/sminnee/)
* [Stig Lindqvist](https://github.com/stojg)
* [Mike Parkhill](https://github.com/mparkhill)

Credit also goes to [Marcus Nyholt](https://github.com/nyeholt/) for the use of the External Content module on top of which Exodus itself is built. The module in its current state actually includes the [`nyeholt/silverstripe-external-content`](https://github.com/nyeholt/silverstripe-external-content) package and bakes it in as a sub-directory rather than using Composer.

...it was just easier that way.
