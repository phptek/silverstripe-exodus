## Troubleshooting

### PHP-FPM

You may need to tweak the settings found in `www.conf`. Contrary to popular belief, the default `pm = dynamic` may not suffice. In (limited) testing we used `pm = static` with `pm.max_children = 25` or greater as opposed to the default `10` which helped crawling a ~250 page site. Tweaking php-fpm gives your application more system resources to call upon during larger site-crawls.

If you have alternatives that work for you. Please [Submit them!](https://github.com/phptek/silverstripe-exodus/issues).

### Docker

If because of a gateway timeout your app container no longer responds (Silverstripe will give you a Toast notification, keep an eye out for it), you'll need to restart it. If you were in the middle of a crawl, once you've restarted the container, just hit the same button in the CMS which should be labelled "Re Crawl" now, and it will pickup where it left-off.

### Redirects

If you know the site to be crawled will redirect, use the redirected (canonical) URL for the site as the value of the "Base URL" field.

### Errors

* _Developers_ keep an eye on your browser's developer-tools' "Network" tab. Not all errors are reported directly in Silverstripe's UI. Please [report any issues you find](https://github.com/phptek/silverstripe-exodus/issues).  
* You may need to re-run a crawl if the selected URL Processing option fails with an on-screen error message. Each option is designed for websites with fairly specific features and URL patterns and may return prematurely.
