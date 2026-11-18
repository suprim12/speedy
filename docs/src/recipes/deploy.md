# Clear on Deploy

Speedy can rebuild every cached page in the background on its own when `php craft up` applies project config changes, such as a new field or a changed section. Visitors keep getting the old pages until the new ones are ready. Turn it on in `config/speedy.php`:

```php
'rebuildAfterDeploy' => true,
```

It’s off by default: on a site with thousands of cached pages, rebuilding them all keeps the server busier for a while after each deploy. Pages are rebuilt a few at a time (`warmConcurrency`), so visitors aren’t slowed down.

A deploy can also change templates, and templates aren’t content, so Speedy can’t tell which pages they affect. If a deploy changes templates but not project config, clear everything after it:

```bash
php craft up
php craft speedy/clear
```

`speedy/clear` also clears your CDN, and queues a warm job for your `warm` list.

On a busy site, rebuild instead, so visitors keep getting the old pages until the new ones are ready:

```bash
php craft up
php craft speedy/clear --rebuild
```

Use `speedy/clear` when the old pages mustn’t be shown at all, such as after removing content for legal reasons. To warm during the deploy instead, so the site is fast the moment it goes live:

```bash
php craft up
php craft speedy/clear
php craft speedy/warm
```

## Release folders

Some deploys put each version of the site in a folder of its own, such as `releases/42`, and switch a `current` link to it once it’s ready. Rollbacks and zero-downtime tools work this way. Shared folders, such as `storage` and `web/assets`, are kept outside the releases and linked into each one.

Speedy saves pages in `web/speedy`, inside the release. Left as it is, each release starts with no pages cached:

- The first visitor to each page waits while it’s built.
- Every release keeps its own copy of the cache, so the disk fills with old copies.
- Speedy keeps its records in `storage`, which is shared, so they no longer match the pages, and the utility page counts pages that are gone.
- Rolling back to an older release sends visitors that release’s old pages, without the changes made since.

### Share the cache folder

Keep `web/speedy` outside the releases, like `web/assets`, and link it into each one. Speedy and the web server both follow the link, so nothing changes in Speedy’s settings or your server rules.

When building the release, leave out any cached pages:

```bash
rm -rf release/web/speedy
```

When activating it, before switching `current`:

```bash
mkdir -p "$SHARED/web/speedy"
ln -s "$SHARED/web/speedy" "$RELEASE/web/speedy"
```

### After the switch

Run Speedy’s commands after `current` points to the new release, so pages are rebuilt with the new templates.

Craft’s `clear-caches/all` includes **Speedy Cache pages**, so it empties the cache on every deploy. Clear only the caches you need instead, such as:

```bash
php craft clear-caches/compiled-templates
php craft clear-caches/data
```

Then rebuild the cached pages when templates changed. Visitors keep getting the old pages until the new ones are ready:

```bash
php craft speedy/clear --rebuild
```

Most deploys don’t change templates, and their pages can stay as they are. To rebuild only when they did, compare with the last deploy in your pipeline, such as:

```bash
if ! git diff --quiet "$LAST_DEPLOYED_COMMIT" HEAD -- templates/; then
  php craft speedy/clear --rebuild
fi
```

Changes that come with project config, such as a new field, appear on pages through templates, so the same check covers them. Leave `rebuildAfterDeploy` off with this setup, or every project config change rebuilds every page. After changing a named image transform, or a site’s name that templates show, click **Rebuild all pages** on the utility page.

::: warning Clearing everything replaces the link
In Speedy 1.4.0 and earlier, clearing everything moves the cache folder aside to empty it in one step. With a linked folder, that moves the link, and the release gets a folder of its own again. This happens with **Clear all pages** on the utility page, `speedy/clear` without `--rebuild`, and **Speedy Cache pages** under **Utilities → Caches** or in `clear-caches/all`. Use **Rebuild all pages** and `speedy/clear --rebuild` instead, and if it happens, link the folder again.
:::

### Pages committed to git

If cached pages were committed before, remove them from git once, keeping the files:

```bash
git rm -r --cached web/speedy
```

## Only in production

```php
// config/speedy.php
return [
    'enabled' => '$SPEEDY_ENABLED',
];
```

```bash
# .env in production
SPEEDY_ENABLED=true
```
