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

### PHP

Tweaking PHP and Nginx/Apache is beyond the remit of advice the authors are willing to provide. What follows therefore comes with a the caveat that these are the current settings in the author's own setup which seems to work without Gateway Timeouts occurring

#### **Gateway Timeout**

You may need to tweak your dev-environment's Nginx and PHP settings found in `/etc/nginx/conf.d/default.conf`, `php.ini` and/or `www.conf` respectively (if using PHP-FPM).

If using Nginx with PHP-FPM, set FPM's `fastcgi_read_timeout` to something sane like `500`:

```
location @my_site {
    ...
    ...
    # Increase read timeout
    fastcgi_read_timeout 500;
    ...
}
```

Ensure that `max_execution_time` is changed in `php.ini` from the default `30s` to something like `300s` for medium sites and upwards of that for larger sites. Trial and error is the only way to know what it should be. If after some tweaking, you're still experiencing timeouts, then setting `max_execution_time` to `0` (unlimited - which is the default for the CLI SAPI anyway) may be helpful.

**WARNING** It's recommended you run your crawls over **non-production sites** to prevent excessive requests slowing the target site down for its other users. Be prepared to kill the PHP process or container if it becomes necessary.

Contrary to popular belief, the default FPM `pm = dynamic` may not suffice. In (limited) testing we used `pm = static` with `pm.max_children = 25` or greater, as opposed to the default `10`, which helped crawling a ~250 page site. Tweaking php-fpm gives your application more system resources to call upon during larger site-crawls.

#### **PHPCrawl**

There is a known issue with PHPCrawl with PHP8. See [this issue](https://github.com/phptek/silverstripe-exodus/issues/24) for more.

#### **Other**

If you have alternatives that work for you, please do [submit them!](https://github.com/phptek/silverstripe-exodus/issues).

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
