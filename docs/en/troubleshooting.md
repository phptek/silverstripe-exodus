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

### Image Size

You may find that images and files over a given size are not being imported. You may be subject to Silverstripe's `default_max_file_size` configuration. By default it's set to 1Mb for specific file-types. Review the Silverstripe documentation to ascertain how to increase this for each type of file affected.

### Thumbnails

As part of image importing, the module will go through each `Image` subclass and publish thumbnails to appear in the CMS' "Files" section. If you see an error in your logs ala `Uncaught Error: Call to undefined function Intervention\Image\Gd\imagecreatefromjpeg()` then it's likely that GD hasn't been properly configured with JPG graphics.

Ensure GD is configured ala `--with-freetype --with-jpeg`.

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

#### **Other**

If you have alternatives that work for you, please do [submit them!](https://github.com/phptek/silverstripe-exodus/issues).

### Docker

#### Gateway Timeout

There are a couple of issues you'll observe which are the result of an HTTP 504:

If you were in the middle of a crawl, open up you're browser's devtools and you'll see something like this:

```
Failed to load resource: the server responded with a status of 504 (Gateway Time-out)
```

If you repeatedly list the contents of the current source's directory e.g. `ls -l `public/assets/static-site-1/` you may observe that the file `urls` is getting larger, despite the timeout. Just wait for it to complete.

 Otherwise, you can restart the container and just hit the same button in the CMS which should now be labelled "Re Crawl". The module used to pickup where it left-off, but at the moment it starts again.

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
