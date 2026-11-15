# Multi-Site and Multiple Servers

## Multi-site

Pages are saved by host and path, such as `example.com/news` and `example.de/nachrichten`, so sites never mix, whether they use different domains or folders like `/de/`.

Clearing works across sites: saving an entry clears its page in every site it appears in. With `warm` set to `'sitemap'`, each site’s sitemap is read.

The server rules work for any number of domains, as they use the request’s host.

## Several web servers

Behind a load balancer, every server must see the same cache, or a page cleared on one is still served by another. Put both folders on shared storage, such as NFS or EFS: mount it at `web/speedy` on each server, and set the data folder:

```php
// config/speedy.php
return [
    'dataPath' => '/mnt/shared/speedy-data',
];
```

Without shared storage, use one server per site, or turn on [Cloudflare](/guides/cloudflare) or [CloudFront](/guides/cloudfront) and let the CDN do the caching.

## Craft Cloud

Craft Cloud runs on several servers and has static caching built in, so you may not need Speedy there.
