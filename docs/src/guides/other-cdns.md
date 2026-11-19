# Fastly, Bunny, KeyCDN and Varnish

Besides [Cloudflare](/guides/cloudflare) and [CloudFront](/guides/cloudfront), Speedy clears Fastly, Bunny, KeyCDN and Varnish. Add their details in **Settings → Plugins → Speedy Cache → CDN**, ideally as environment variables. Once they’re set, cached pages are sent with `Cache-Control: public, s-maxage=…`, so the CDN keeps them, and Speedy clears changed pages from it. **Test** buttons appear on the utility page.

![The CDN tab: a card each for Cloudflare, CloudFront, Fastly, Bunny, KeyCDN and Varnish](/images/settings-cdn.jpg)

## Fastly

1. In Fastly, create an API token with **purge_select** and **purge_all** scope for your service.
2. Copy the service ID from the service’s page.
3. Add them to `.env` and enter `$FASTLY_TOKEN` and `$FASTLY_SERVICE_ID` in the settings:
   ```bash
   FASTLY_TOKEN="…"
   FASTLY_SERVICE_ID="…"
   ```

Changed pages are purged by URL; **Clear all pages** purges the whole service. Speedy notices Fastly from the `Fastly-FF` header.

## Bunny

1. In the Bunny dashboard, copy your API key from **Account → API**.
2. Copy the pull zone’s ID from its page.
3. Add them to `.env` and enter `$BUNNY_API_KEY` and `$BUNNY_PULL_ZONE_ID` in the settings.

Changed pages are purged by URL; **Clear all pages** purges the pull zone.

## KeyCDN

1. In the KeyCDN dashboard, copy your API key from **Account Settings → Authentication**.
2. Copy the zone’s ID from **Zones**.
3. Add them to `.env` and enter `$KEYCDN_API_KEY` and `$KEYCDN_ZONE_ID` in the settings.
4. Enter the zone URL, such as `example-1a2b.kxcdn.com`. Leave it blank if the zone has **Cache Key Host** turned on, so KeyCDN keeps pages by your own domain.

Changed pages are purged by URL, in batches, as KeyCDN takes one purge a second; **Clear all pages** purges the zone. Speedy notices KeyCDN from the `X-Pull` header it sends with each request.

## Varnish

List your Varnish servers, one per line, such as `http://127.0.0.1:6081`. Speedy sends each one a `PURGE` request for every changed page, with the page’s host, and a `BAN` request to clear everything.

Varnish must allow those requests from your web server. Add this to your VCL:

```vcl
acl purge {
    "127.0.0.1";
    # your web servers
}

sub vcl_recv {
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) { return (synth(405, "Not allowed")); }
        return (purge);
    }
    if (req.method == "BAN") {
        if (!client.ip ~ purge) { return (synth(405, "Not allowed")); }
        ban("req.http.host == " + req.http.host + " && req.url ~ " + req.http.X-Ban-Url);
        return (synth(200, "Banned"));
    }
}
```

**Test Varnish** on the utility page checks that `PURGE` is allowed.

## Another CDN

Other plugins and modules can add a CDN. See [Events](/guides/events#add-a-cdn).

## Netlify and Vercel

Craft runs on a PHP server, so Netlify and Vercel can’t host it or sit in front of it as a CDN; they’re for static and JavaScript sites. Speedy caches pages on your Craft server, and any of the CDNs above can sit in front of it.
