# Cloudflare

If your site is behind Cloudflare, Speedy can let Cloudflare keep your pages too, at its edge close to visitors, and clear them when content changes.

Speedy detects Cloudflare from its `CF-Ray` header and says so in **Settings → Plugins → Speedy Cache** and on the utility page.

## Without a token

Speedy still works: pages are cached on your server. Speedy tells Cloudflare not to keep HTML pages (`Cache-Control: no-cache, s-maxage=0`), so Cloudflare never shows a page Speedy can’t clear. Images, CSS and JavaScript are cached by Cloudflare as usual.

## With a token

1. In Cloudflare, go to **My Profile → API Tokens → Create Token → Create Custom Token**.
2. Give it two permissions: **Zone → Cache Purge → Purge**, and **Zone → Cache Rules → Edit** so Speedy can set up the cache rules below.
3. Under **Zone Resources**, choose **Include → Specific zone →** your domain.
4. Copy the token. Then copy the **Zone ID** from the domain’s **Overview** page.
5. Add both to `.env`:
   ```bash
   CLOUDFLARE_TOKEN="…"
   CLOUDFLARE_ZONE_ID="…"
   ```
6. In **Settings → Plugins → Speedy Cache**, enter `$CLOUDFLARE_TOKEN` and `$CLOUDFLARE_ZONE_ID`, and save.
7. On the utility page, click **Test Cloudflare**.

Cached pages are now sent with `Cache-Control: public, s-maxage=31536000, max-age=0, stale-while-revalidate=86400, stale-if-error=86400`: Cloudflare keeps them, browsers check back each time, and Speedy clears Cloudflare whenever a page changes. If your site is down, Cloudflare keeps sending the last copy of each page for a day.

## Make Cloudflare keep pages

By default, Cloudflare only caches files like images. It keeps pages once a **Cache Rule** lets it.

On the utility page, open the **Setup** tab and click **Set up Cloudflare**. Speedy adds two rules to each zone:

1. **Keep pages** for the sites’ domains, for as long as their `Cache-Control` header says. A response without one isn’t kept, so Cloudflare only keeps the pages Speedy can clear.
2. **Get their own pages:** visitors with the cookies of a login, a cart or a multi-step form, Ajax requests, the control panel, actions and `/index.php`. This rule comes after the first, so it wins.

Your other cache rules stay as they are. Clicking it again updates Speedy’s two rules, such as after adding a domain or changing `cpTrigger`. The tab shows when they were last set up, and for which domains.

If the token can’t edit cache rules, Speedy says so: add **Zone → Cache Rules → Edit** to it. A site whose base URL is `@web` needs its domains in `allowedHosts` first, so Speedy knows which they are.

### Setting the rules up yourself

In Cloudflare, go to **Caching → Cache Rules** and add a rule for your domain: **Eligible for cache**, and **Edge TTL → Use cache-control header if present, bypass cache if not**. Then add a second rule, below it, with **Bypass cache** for `/admin*` and `/actions*`, and when the `Cookie` header contains `_identity`, `_commerce_cart` or `speedy_state`, so logged-in visitors, carts and multi-step forms get their own pages.

## Several domains

A multi-site install on several domains has a Cloudflare zone for each. Give Speedy each domain’s zone ID in `config/speedy.php`, and one token that can clear them all (under **Zone Resources**, include each zone, or **All zones**):

```php
'cloudflareToken' => '$CLOUDFLARE_TOKEN',
'cloudflareZones' => [
    'example.com' => '$CLOUDFLARE_ZONE_COM',
    'example.de' => '$CLOUDFLARE_ZONE_DE',
],
```

To keep the zones in `.env`, give `cloudflareZones` one variable, as JSON or as `domain=zone` pairs:

```php
'cloudflareZones' => '$CLOUDFLARE_ZONES',
```

```bash
CLOUDFLARE_ZONES="example.com=abc123,example.de=def456"
```

Each page is cleared from its own domain’s zone. A domain covers its subdomains, so `example.com` covers `www.example.com`. Pages on other domains use the **Zone ID** from the settings page, if there is one, and are otherwise left alone.

## How clearing works

- Changed pages are cleared by URL, 30 at a time, from their domain’s zone.
- **Clear by pattern** with a pattern ending in `/*`, such as `/news/*`, clears that prefix from Cloudflare in one request, such as `example.com/news/`, on each of the sites’ domains. That also clears copies Speedy never had, such as ones with `?utm_source=` added. Other patterns, such as `news*`, clear by URL.
- **Clear site** clears the site’s domain from Cloudflare by host, or its folder by prefix for a site such as `example.com/de`. A site whose base URL is `@web` is cleared by URL.
- When the cleared pages are rebuilt, Cloudflare is cleared by prefix or host after the rebuilds, so it doesn’t fetch the old copies again.
- Every Cloudflare plan can clear by prefix and host; the Free plan allows 5 such requests a minute.
- **Clear all pages** clears everything in Cloudflare too, in every zone.
- Results show on the utility page, and failures in the log.
