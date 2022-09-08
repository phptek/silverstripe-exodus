## Troubleshooting

### Logging

By default the module will log to the path set for the class who'se logic you want to analyse:

```
PhpTek\Exodus\Task\StaticSiteRewriteLinksTask:
  log_file: '/tmp/exodus-failed-rewrite.log'

PhpTek\Exodus\Tool\StaticSiteUtils:
  log_file: '/tmp/exodus.log'
```

It may not be desirable to enable logging on shared or cloud hosting environments, so set the value of `log_file` to `null` in your own project's config if you wish to disable logging.

### PHP-FPM

You may need to tweak the settings found in `www.conf`. Contrary to popular belief, the default `pm = dynamic` may not suffice. In (limited) testing we used `pm = static` with `pm.max_children = 25` or greater, as opposed to the default `10`, which helped crawling a ~250 page site. Tweaking php-fpm gives your application more system resources to call upon during larger site-crawls.

If you have alternatives that work for you. Please [Submit them!](https://github.com/phptek/silverstripe-exodus/issues).

### Docker

#### Gateway Timeout

If because of a gateway timeout your app container no longer responds (Silverstripe will give you a Toast notification, keep an eye out for it), you'll need to restart it. If you were in the middle of a crawl, once you've restarted the container, just hit the same button in the CMS which should be labelled "Re Crawl" now, and it will pickup where it left-off.

#### Aborted crawling-process with crawler-id...

During a crawl, a crawler ID is written to the F/S by PHPCrawler. Seing this message means that the file no longer exists for some reason. In a Docker context, this means you may have suffered a Gateway Timeout and restarted the container. You can work around this by re-creating a file at: `public/assets/static-site-x/crawlerid` and echoing the missing crawler ID into it:

```
local #> docker compose exec my_container bash
my_container #> echo "1234567" > public/assets/static-site-6/crawlerid
```

### Redirects

If you know the site to be crawled will redirect, use the redirected (canonical) URL for the site as the value of the "Base URL" field.

### Errors

* _Developers_ keep an eye on your browser's developer-tools' "Network" tab. Not all errors are reported directly in Silverstripe's UI. Please [report any issues you find](https://github.com/phptek/silverstripe-exodus/issues).  
* You may need to re-run a crawl if the selected URL Processing option fails with an on-screen error message. Each option is designed for websites with fairly specific features and URL patterns and may return prematurely.
