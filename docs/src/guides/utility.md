# The Utility Page

**Utilities → Speedy Cache** shows what’s cached and lets you clear it. It needs the **Utilities → Speedy Cache** permission.

![The Speedy Cache utility page: totals, tips and actions at the top, then the Pages, Recent clears, Performance and Setup tabs](/images/utility.jpg)

## At the top

- **Caching:** on or off.
- **Pages cached** and their size on disk.
- **Coverage:** how many of your content pages are cached, such as “412 of 530 content pages”. See [Coverage](#coverage).
- **CDN:** the one Speedy detected, and whether it can clear it.
- **CloudFront paths this month,** when CloudFront is set up.

### Checks and tips

Under the totals, Speedy lists anything that needs attention, most important first:

- **Problems:** another page-cache plugin enabled at the same time, a folder Speedy can’t write to, failed Speedy queue jobs, a CDN clear that failed.
- **Checks:** caching turned off, `devMode` on in production, CloudFront close to its free paths.
- **Tips:** your site is behind a CDN Speedy could clear, pages that clear whenever any entry is saved (a query without `.section()`), image transforms generated on first view, and server rules not checked yet.

A tip that mentions a tab, such as **Performance**, links straight to it.

### Actions

Under the totals, three cards: **Every page**, **Some pages**, and **Why isn’t this page cached?**

| Button | What it does |
|---|---|
| **Rebuild all pages** | Rebuilds every cached page in the background. Visitors keep getting the old copies until the new ones are ready, so a busy site never has a moment with nothing cached |
| **Clear all pages** | Removes every cached page, clears your CDN, then warms the pages you listed. Visitors wait for pages to be built again, so prefer **Rebuild all pages** |
| **Clear URL** | Clears one page, such as `https://example.com/news`, with its query string versions such as `?page=2`. With `*`, it clears every page matching the pattern: `https://example.com/news/*` clears everything under `/news/`, and a path, such as `/news/*`, matches every domain. Up to 10 `*` |
| **Clear section** | Clears every page that shows a section: its listings, including those of one entry type, and the pages that show its entries, such as after changing its templates. They’re rebuilt straight away. A section of more than about 500 entries is cleared in the background, and **Recent clears** shows how many |
| **Clear site** | Clears every page of one site. Shown when there are several sites. A site at `/de/` on the same domain as another owns the pages under `/de/` |
| **Warm pages** | Builds the pages in your `warm` list now. Shown when `warm` is set |

### Why isn’t this page cached?

Enter an address, such as `https://example.com/homes?view=map`, or a path, such as `/homes`, under **Why isn’t this page cached?**, and click **Check**. Speedy opens the page as a logged-out visitor would, a second time if the first visit built it, and says what happened, in words. Redirects aren’t followed, and the `warmUsername` and `warmPassword` login is used for a site behind one:

- **Cached**, how long ago it was built, and whether the web server or PHP sends it.
- **Not cached**, why, and what to change. A query string names the parameters to add under **Query strings**; a page that sets a cookie names the cookie; **Never cache** names the pattern it matched; a redirect says where it goes.

When you’re logged in, it also reminds you that you get fresh pages yourself. Only the site’s own addresses can be checked.

## Tabs

Below the actions, the page has four tabs. Each has its own address, such as `utilities/speedy#queries`, to link to or bookmark.

### Pages

#### Coverage

The pages of entries, categories, products and other elements with their own URL, and how many of them are cached, by section or group. The sections with the most pages not cached come first.

- **Not cached yet** lists the pages no visitor has opened since they were last cleared.
- **Couldn’t be built** lists the pages Speedy tried and couldn’t cache, with why, such as `status 500` for an error in a template, `redirects to /news` for a page that sends visitors elsewhere, or `SKIP private` for a page marked private.
- **Build the pages not cached** builds them all in the background, the most important first, and tries the ones that couldn’t be built again.

Pages left out by **Never cache** or **Only cache** aren’t counted. Nor are sections without URLs, entries whose URI has a query string, such as `now-selling?type=land`, which open another page, or sections and category groups with URLs whose template doesn’t exist, such as a section whose entries are only shown on other pages: coverage names them under the table. Listings with a query string, such as `?page=2`, and pages from routes or templates without an element aren’t counted either: they’re in the list of cached pages below. Coverage is counted at most every five minutes.

#### Cached pages

Every cached page, sorted by URL, with a search box. For each page:

- **Cached:** when it was built, and when it will be rebuilt for scheduled content.
- **Clears when these change:** what the page depends on, in words: “Entries: News”, “Entry: Summer Sale”, “Any of: Entries”. A page with nothing listed only clears when everything is cleared, such as a page from a template with no entries.
- **Clear:** clears that page.

### Recent clears

The last clears, with when, why, how many pages and which URLs. The latest 10 are shown, with **Show more** at the bottom for the rest, and **Show less** to fold them away again:

![Recent clears](/images/log.jpg)

Each line names what changed: “Saved entry ‘Summer Sale’ → 3 pages”, “Resaved 1,200 entries → 340 pages”, “Cleared from the command line”. CDN failures show here too.

### Performance

Filled in as Speedy rebuilds pages in the background, so visitors’ requests are never measured. The tab shows how many pages need a look.

- **Slowest pages:** the 10 pages that took longest to build, over half a second, with their number of queries. Visitors get the cached copy, but slow pages delay rebuilds, and logged-in visitors wait this long.
- **Cleared by any change:** queries that don’t choose a section or group, such as `craft.entries.relatedTo(entry)`, one for each template line, with the pages it’s on. A query in your layout is listed once, with every page, and comes first. Saving any entry clears them, so an import can clear them again and again. Narrow the query, for example with `.section('news')`.
- **Repeated queries:** pages that run the same query in a loop, below.

Each query names the template and line that ran it: click it to see the lines around it, with that line highlighted. Each list shows its first 10 rows, and each page its first 3 repeated queries, with **Show more** at the bottom for the rest. A query run by a string rendered as a template, such as a plugin’s setting, names the template file that rendered it.

![The Performance tab: the slowest pages with their build times, a page cleared by any change with the template line of its query, and a page that runs the same query in a loop](/images/performance.jpg)

Pages that run the same database query again and again, usually once for each item in a loop. This is called an N+1 query: a news listing of 30 entries that reads each entry’s categories runs 30 extra queries, where eager-loading runs one.

Cached pages are still fast, but these pages are slow to rebuild, and slow for every visitor who isn’t sent a cached page, such as logged-in visitors and shoppers with a cart.

Speedy counts the queries while it rebuilds pages in the background, so visitors’ requests aren’t slowed down. A query run 10 times or more on one page is listed, with where it ran and what to do:

> **21×** Categories are fetched one item at a time inside a loop at news/_index, line 14. Eager-load them with .with() on the query that loops, or add .eagerly() where they’re used.

For example:

```twig
{# Before: one query for each entry’s categories #}
{% for entry in craft.entries().section('news').all() %}
  {{ entry.categories.all()|join(', ') }}
{% endfor %}

{# After: one query for them all #}
{% for entry in craft.entries().section('news').with(['categories']).all() %}
  {{ entry.categories|join(', ') }}
{% endfor %}
```

A page drops off the list the next time it’s rebuilt without repeated queries. Turn the report off with `queryReport`:

```php
'queryReport' => false,
```

### Setup

Things you need once, or when something is wrong:

- **Serve pages without PHP:** the rules for your web server, ready to copy, and **Check server serving**, which checks that the web server sends cached pages without PHP. See [Server rules](/getting-started/server-rules).
- **CSS and JavaScript:** **Check CSS and JavaScript** looks at up to 100 cached pages and says whether any link to CSS or JavaScript files that are gone, such as after a deploy replaced the built files or Craft’s published plugin files, with an example. **Rebuild all pages** fixes them. It only runs when you click it, and only looks for the files on disk.
- **CDN:** the last result from each CDN, a **Test** button for each one that’s set up, and **Set up Cloudflare**, which adds the cache rules Cloudflare needs to keep pages. See [Cloudflare](/guides/cloudflare#make-cloudflare-keep-pages).
- **Support report:** a plain-text summary for support: versions, settings, CDN status, checks, recent clears, slow pages and queries, and installed plugins. Keys and tokens only appear as “set”. **Copy support report** copies it; **Email support** starts an email with it, or copies it to paste in when it’s too long for the email link.

![The support report: versions, caching settings, CDN, checks, recent clears and plugins](/images/report.jpg)

## On each entry

![The details panel of an entry: Page cache, Cached, 26 seconds ago, shown on 3 cached pages](/images/entry-status.jpg)

The details panel of entries, categories, products and other elements with pages shows a **Page cache** line: whether its page is cached and when, or why it isn’t (caching off, or a **Never cache** pattern), and how many cached pages show it.

When its page is cached, or other cached pages show it, **Clear its cached pages** clears them all, such as after fixing a typo in a template. It needs the same permissions as clearing from an element index, below, and works in slideouts too.

## Clear from an element index

![The actions menu of the entries index, with Clear cached pages](/images/element-action.jpg)

Select entries, categories or Commerce products, and choose **Clear cached pages** from the actions menu. It clears every cached page that shows them.

It needs the **Utilities → Speedy Cache** permission, or, for editors who shouldn’t see the rest of the utility, **Speedy Cache → Clear cached pages from entry, category and product indexes**.

## Dashboard widget

![The Speedy Cache widget: 15 pages cached, caching on, no CDN, and the last three clears](/images/widget.jpg)

Add **Speedy Cache** to your dashboard (**Dashboard → New widget**) for the same at a glance: pages cached, whether caching is on, the CDN Speedy clears, any problems and the last few clears. It needs the same permission as the utility.

## Craft’s caches utility

**Utilities → Caches** has a **Speedy Cache pages** option, which clears all pages along with Craft’s other caches.
