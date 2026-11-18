# Clear on Deploy

Speedy clears pages when content changes, but templates aren’t content: after a deploy that changes templates, Speedy can’t tell which pages they affect. Rebuild the cached pages after it:

```bash
php craft up
php craft speedy/clear --rebuild
```

Every cached page is rebuilt in the background, a few at a time (`warmConcurrency`), and cleared from your CDN as it’s replaced. Visitors keep getting the old pages until the new ones are ready, so nobody waits for a page to be built.

Rebuild after a new front-end build too. Cached pages link to the CSS and JavaScript files they were built with, and build tools such as Vite and webpack name each file after its contents, such as `main-4f2a9c.css`, so a changed style gets a new name. Deploys that change neither, such as PHP or config, need nothing: their pages can stay as they are.

### Keep the old CSS and JavaScript

Until a page is rebuilt, it still links to the old files. If the deploy removes them, such as by replacing `web/dist` with the new build, those pages show without their styles and scripts until they’re rebuilt. Keep the previous build’s files next to the new ones: the names never clash, so old pages keep working and new pages use the new files. Remove files more than a few days old now and then.

## Clearing instead

`speedy/clear` without `--rebuild` removes every page and clears your CDN, so each page is built again by its next visitor. Use it when the old pages mustn’t be shown at all, such as after removing content for legal reasons:

```bash
php craft up
php craft speedy/clear
```

It also queues a warm job for your `warm` list, so your important pages are built first.

## Project config changes

`rebuildAfterDeploy` rebuilds every cached page in the background whenever a deploy applies project config changes:

```php
'rebuildAfterDeploy' => true,
```

It’s off by default, as it rebuilds every page for any change, including ones visitors never see, such as user permissions or a plugin’s settings. Most changes that show on pages, such as a new field, also change a template, so rebuilding when templates change covers them. Changes Speedy already handles itself, such as a section’s URL format, instant links or an SEO plugin’s settings, rebuild their pages without it.

Turn it on if content types change without templates changing, such as a site that renders fields generically. Otherwise, after changing a named image transform, or a site’s name that templates show, click **Rebuild all pages** on the utility page.

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

# Keep the previous build’s CSS and JavaScript, for pages built with it
cp -rnp "$PREVIOUS/web/dist/assets/." "$RELEASE/web/dist/assets/"
find "$RELEASE/web/dist/assets" -type f -mtime +14 -delete
```

`$PREVIOUS` is the release `current` points to before the switch. `cp -n` never replaces a file of the new build, and `-p` keeps each file’s date, so old files are removed once they’re two weeks old.

### After the switch

Run Speedy’s commands after `current` points to the new release, so pages are rebuilt with the new templates.

Craft’s `clear-caches/all` includes **Speedy Cache pages**, so it empties the cache on every deploy. Clear only the caches you need instead, such as:

```bash
php craft clear-caches/compiled-templates
php craft clear-caches/data
```

Then rebuild the cached pages when templates or the front-end build changed. Visitors keep getting the old pages until the new ones are ready:

```bash
php craft speedy/clear --rebuild
```

Many deploys change neither, and their pages can stay as they are. To rebuild only when they did, compare with the last deploy in your pipeline: the templates in git, and the build’s manifest, which lists the built files’ names:

```bash
if ! git diff --quiet "$LAST_DEPLOYED_COMMIT" HEAD -- templates/ \
  || ! cmp -s "$PREVIOUS/web/dist/.vite/manifest.json" "$RELEASE/web/dist/.vite/manifest.json"; then
  php craft speedy/clear --rebuild
fi
```

Use your build’s manifest path, such as `web/dist/manifest.json`.

Leave `rebuildAfterDeploy` off with this check, as above.

::: warning Clearing everything replaces the link
In Speedy 1.4.0 and earlier, clearing everything moves the cache folder aside to empty it in one step. With a linked folder, that moves the link, and the release gets a folder of its own again. This happens with **Clear all pages** on the utility page, `speedy/clear` without `--rebuild`, and **Speedy Cache pages** under **Utilities → Caches** or in `clear-caches/all`. Use **Rebuild all pages** and `speedy/clear --rebuild` instead, and if it happens, link the folder again.
:::

### Pages committed to git

If cached pages were committed before, remove them from git once, keeping the files:

```bash
git rm -r --cached web/speedy
```

## Off while developing

Caching gets in the way while building templates, so turn it off on your own machine, and keep it on for staging, so problems show there before they reach production:

```php
// config/speedy.php
return [
    'enabled' => '$SPEEDY_ENABLED',
];
```

```bash
# .env on your own machine
SPEEDY_ENABLED=false

# .env on staging and production
SPEEDY_ENABLED=true
```
