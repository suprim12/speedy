# Serving Without PHP

Speedy works as soon as it’s installed: PHP sends cached pages without building them, which is already many times faster. For the fastest pages, let the web server send them itself, so PHP never starts.

On our test site, the store locator page took **316 ms** to build, **30 ms** from Speedy through PHP, and **1 ms** from the web server.

## Get the rules

They’re in **Utilities → Speedy Cache → Serve pages without PHP**, or print them:

```bash
php craft speedy/server-rules            # Nginx
php craft speedy/server-rules --apache   # Apache
```

The rules use your settings, so print them again if you change `cachePath` or set up a CDN, and after updating Speedy if you use a CDN: 1.3 adds `stale-while-revalidate` and `stale-if-error` to the `Cache-Control` header they send. 1.4.1 adds Brotli copies: copy the rules again to send them.

## Nginx

Add the rules to your site’s `server { … }` block, before `location / { … }`:

```nginx
# Speedy Cache: serve cached pages without PHP.
set $speedy_page "/speedy/$host$uri/index.html";
if ($request_method !~ ^(GET|HEAD)$) { set $speedy_page ""; }
if ($args != "") { set $speedy_page ""; }
if ($http_cookie ~* "_identity|speedy_state|_commerce_cart") { set $speedy_page ""; }
if ($http_x_requested_with != "") { set $speedy_page ""; }
if ($http_hx_request != "") { set $speedy_page ""; }
if ($http_x_up_target != "") { set $speedy_page ""; }
if ($http_x_speedy_warm != "") { set $speedy_page ""; }
if (-f $document_root$speedy_page) { rewrite ^ $speedy_page last; }

location ^~ /speedy/ {
    internal;
    default_type text/html;
    gzip_static on;
    # brotli_static on;  # Sends the Brotli copy, if nginx has the Brotli module (ngx_brotli).
    add_header X-Speedy "STATIC" always;
    add_header Cache-Control "no-cache, s-maxage=0" always;
}
```

Then test and reload Nginx: `sudo nginx -t && sudo systemctl reload nginx`.

What the rules do:

- Only GET requests without a query string get the cached file, from visitors who aren’t logged in, have no cart and have nothing in their session (`speedy_state`), and not Ajax requests (`X-Requested-With`, `HX-Request`, `X-Up-Target`).
- Cookies you list in `skipCookies` are added to the cookie line.
- Speedy’s own rebuild requests (`X-Speedy-Warm`) always reach Craft.
- `internal` stops anyone opening the cache folder directly.
- nginx doesn’t pass the server block’s `add_header` lines on to a `location` that has its own, so cached pages wouldn’t get headers such as `Strict-Transport-Security` or `Content-Security-Policy`. Repeat them in the Speedy block, where the rules show an example.
- `gzip_static` sends the compressed copy Speedy saves next to each page (`index.html.gz`), so nginx doesn’t compress it on every request. It’s included in most nginx builds; if `nginx -t` says it’s unknown, remove that line.
- When PHP has the Brotli extension, Speedy also saves a Brotli copy (`index.html.br`), about a fifth smaller than gzip. If your nginx has the Brotli module, remove the `#` before `brotli_static on;` to send it.
- Everything else goes to Craft as usual, which still serves cached pages through PHP.

## Apache

Add the rules to `web/.htaccess`, after `RewriteEngine On` and before Craft’s own rules:

```apache
# Speedy Cache: serve cached pages without PHP.

# The cache folder can’t be opened directly, only through the rules below.
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteRule ^speedy/ - [F]
RewriteRule ^speedy/.+\.html\.br$ - [T=text/html,E=no-gzip:1,E=no-brotli:1,E=SPEEDY_BR:1,L]
RewriteRule ^speedy/.+\.html\.gz$ - [T=text/html,E=no-gzip:1,E=no-brotli:1,E=SPEEDY_GZ:1,L]

# Requests that must reach Craft skip the next three rules.
RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$ [OR]
RewriteCond %{QUERY_STRING} !^$ [OR]
RewriteCond %{HTTP_COOKIE} (_identity|speedy_state|_commerce_cart) [NC,OR]
RewriteCond %{HTTP:X-Requested-With} !^$ [OR]
RewriteCond %{HTTP:HX-Request} !^$ [OR]
RewriteCond %{HTTP:X-Up-Target} !^$ [OR]
RewriteCond %{HTTP:X-Speedy-Warm} !^$
RewriteRule .* - [S=3]

# The Brotli or gzip copy, for browsers that accept it, or the page itself.
RewriteCond %{HTTP:Accept-Encoding} \bbr\b
RewriteCond %{DOCUMENT_ROOT}/speedy/%{HTTP_HOST}%{REQUEST_URI}/index.html.br -s
RewriteRule .* /speedy/%{HTTP_HOST}%{REQUEST_URI}/index.html.br [L,E=SPEEDY:1]
RewriteCond %{HTTP:Accept-Encoding} gzip
RewriteCond %{DOCUMENT_ROOT}/speedy/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz -s
RewriteRule .* /speedy/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz [L,E=SPEEDY:1]
RewriteCond %{DOCUMENT_ROOT}/speedy/%{HTTP_HOST}%{REQUEST_URI}/index.html -s
RewriteRule .* /speedy/%{HTTP_HOST}%{REQUEST_URI}/index.html [L,E=SPEEDY:1]

Header set X-Speedy "STATIC" env=REDIRECT_SPEEDY
Header set Cache-Control "no-cache, s-maxage=0" env=REDIRECT_SPEEDY
Header set Vary "Accept-Encoding" env=REDIRECT_SPEEDY
Header set Content-Encoding "gzip" env=SPEEDY_GZ
Header set Content-Encoding "br" env=SPEEDY_BR
```

`mod_rewrite` and `mod_headers` must be enabled. Like the nginx rules, they send the compressed copy to browsers that accept it, Brotli when there is one, and refuse direct requests to the cache folder. Tested on Apache 2.4.

Speedy also keeps a small `.htaccess` in `web/speedy/` that refuses direct requests, so the folder is closed on Apache even before you add the rules. Leave it in place. On nginx, the folder is closed by the rules, which mark it `internal`. Until they’re added, cached pages can be opened at their own address, such as `/speedy/example.com/index.html`: they’re the same public pages, and **Check server serving** on the utility page warns about it.

## Git and deploys

Cached pages are built on each server, so they don’t belong in git or a deploy. Speedy keeps a `.gitignore` in `web/speedy/` so git leaves the folder alone. If pages were committed before, remove them from git once, keeping the files:

```bash
git rm -r --cached web/speedy
```

If you deploy by copying files, such as with rsync, leave `web/speedy/` out, so one server’s pages aren’t copied over another’s.

## Check it

In **Utilities → Speedy Cache**, click **Check server serving**. Speedy loads your homepage twice and tells you who sent the second copy:

![A message in the control panel: Cached pages are served without PHP, in 1 ms.](/images/server-check.jpg)

Or with curl: `x-speedy: STATIC` means the web server sent it.

## Laravel Forge, Ploi, DDEV and others

- **Forge and Ploi:** edit the site’s Nginx configuration in their dashboard and paste the rules above `location /`.
- **DDEV:** save the rules as `.ddev/nginx/speedy.conf` and run `ddev restart`.
- **Hosts that don’t let you edit the server config:** skip this step. Speedy still serves cached pages through PHP.
- **Craft Cloud and Servd**, the Craft-specific hosts, cache pages themselves, so you may not need Speedy there. On Craft Cloud, and any host that sets `CRAFT_EPHEMERAL`, the disk is temporary, so Speedy turns itself off and says so on the utility and settings pages.
