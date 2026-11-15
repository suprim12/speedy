# Multi-Site and Multiple Servers

## Multi-site

Pages are saved by host and path, such as `example.com/news` and `example.de/nachrichten`, so sites never mix, whether they use different domains or folders like `/de/`.

Clearing works across sites: saving an entry clears its page in every site it appears in. With `warm` set to `'sitemap'`, each site’s sitemap is read.

The server rules work for any number of domains, as they use the request’s host.

## Several web servers

Behind a load balancer, every server must see the same cache, or a page cleared on one is still served by another. There are two ways.

### Keep pages in Redis

If Craft’s cache is Redis (or Memcached), keep pages there. In **Settings → Plugins → Speedy Cache → Advanced**, set **Where pages are kept** to **Craft’s cache**, or:

```php
// config/speedy.php
'storage' => 'craftCache',
```

- Every server shares the pages, what they depend on, the log and the build locks.
- Pages are stored compressed and expire after 30 days without a rebuild, so Redis never fills up with old pages.
- Clearing everything is instant, however many pages there are.
- Pages are sent by PHP (about 30 ms), as the web server can’t read Redis; the server rules don’t apply.
- Set Craft’s `mutex` component to Redis too, so build locks are shared between servers.

If Craft’s cache is the database, Speedy warns you: pages would grow the database. If it’s files on each server, it warns that they aren’t shared.

### Share the cache folder

Put both folders on shared storage, such as NFS or EFS: mount it at `web/speedy` on each server, and set the data folder:

```php
// config/speedy.php
return [
    'dataPath' => '/mnt/shared/speedy-data',
];
```

Without shared storage, use one server per site, or turn on [Cloudflare](/guides/cloudflare) or [CloudFront](/guides/cloudfront) and let the CDN do the caching.

## Craft Cloud

Craft Cloud runs on several servers and has static caching built in, so you may not need Speedy there.
