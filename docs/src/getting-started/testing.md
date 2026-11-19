---
description: Every Speedy Cache release passes over 220 automated tests on two Craft versions, nginx and Apache, plus real-browser checks for forms, SEO plugins and Alpine.js.
---

# How Speedy Is Tested

A page cache sits between your site and every visitor, so a mistake in it shows on every page. Speedy is tested before every release, against real web servers and real requests, not only in isolation.

## Before every release

| Check | What it runs |
|---|---|
| **Automated tests** | Over 220 tests, run in full on each setup below |
| **Craft 5.11, PHP 8.3, nginx** | The current Craft release, with PHP sending cached pages |
| **Craft 5.6, PHP 8.2, Apache** | The oldest Craft and PHP Speedy supports, with Speedy’s Apache rules in place and Craft’s `index.php?p=` rewrite, so the web server sends cached pages itself |
| **Real requests** | Pages are requested over HTTP, the way visitors and browsers ask for them, with cookies, compression, conditional requests and forms |
| **A real browser** | Headless Chrome opens cached pages to check that scripts start, such as Alpine.js components |
| **Static analysis** | PHPStan and the Craft coding standard, with no errors |

A release only goes out when all of it passes.

## What the tests cover

### Nobody gets someone else’s page

The most important promise of a page cache: a page built for one visitor never reaches another.

- Logged-in visitors, carts, Ajax requests and pages that set a cookie are never cached.
- A made-up cookie can’t get a visitor’s version saved as the copy everyone gets.
- A multi-step form keeps each visitor on their own step, and one visitor’s “Thanks” message never shows to anyone else.
- The browser that built a page with its own form token is never sent a stale copy of it later.
- Made-up `Host` headers and ports, and forwarding headers that name another host, are never cached, and can’t write files outside the cache folder.
- Signed tokens for dynamic parts and background rebuilds can’t be reused for another page or another template.

### Pages stay correct

- Saving an entry clears the pages that show it, and only those. Saving without changes, drafts and autosaves clear nothing.
- Entries that go live or expire at a set time clear their listings at that moment.
- Commerce stock and prices clear the products they changed, including prices that start or end on a date and pricing rules that are deleted.
- A page whose content changes while it’s being rebuilt is rebuilt again, so the old copy never wins.
- Replaced images clear the pages that show them, and pages built before their image transforms are rebuilt once the transforms are ready.
- Query strings: tracking parameters are ignored, listed parameters each get a page of their own, and made-up values can’t fill the disk.

### Forms, plugins and scripts

- **Forms:** cached pages get a fresh CSRF token for each visitor. A form from a form plugin is sent from a cached page and accepted, with its CSS and JavaScript loading.
- **SEO plugins:** their meta tags, titles and JSON-LD are kept in the cached page.
- **Alpine.js:** in a real browser, a component on a cached page starts and responds to clicks, and one inside a dynamic part starts once the part arrives.
- **Dynamic parts:** each is built for its visitor, and one with an error doesn’t keep the others from loading.

### Servers, deploys and speed

- **Apache and nginx:** the server rules send cached pages without PHP, with gzip and Brotli copies for browsers that accept them, and keep the cache folder closed to direct requests.
- **304 Not Modified:** a browser that already has a page gets an empty answer instead of the page again.
- **Background rebuilds:** they work behind a password, through `index.php?p=` rewrites, and on sites with slow pages, where a job stops before the queue’s time runs out and leaves the rest to the next one.
- **Deploys:** a cache folder shared between releases stays shared when everything is cleared, and a folder linked by mistake is never emptied.
- **Disk and size:** pages stop being saved before the disk fills or the cache reaches its size limit, and a page that can’t be saved completely is never sent.
- **Redis:** pages and their tags work in Craft’s cache too, for several servers.

### CDNs

- **Cloudflare:** the cache rules are set up without touching your other rules, and running it again only updates Speedy’s own. A token without the right permission says which one to add.
- **CloudFront:** requests are signed exactly as AWS’s own reference example, and changed pages are grouped into wildcards to stay within the free paths.
- **Fastly, Bunny, KeyCDN and Varnish:** pages are purged by URL, and failures show the service’s own message.

CDN tests check each request against the service’s documented API, rather than sending it to a live account.

### The control panel

- **Why isn’t this page cached?** gives an answer and a fix for every reason a page isn’t cached, and only opens the site’s own pages.
- Clearing pages needs the utility or the clearing permission.
- Names from your content, such as section and site names, are escaped on the utility page.
- **Clear all pages** also resets the Performance report and Speedy’s own failed jobs, never another plugin’s.

## Found something?

If a page isn’t cached when it should be, start with **Why isn’t this page cached?** on the utility page. For anything else, copy the support report from the **Setup** tab and send it with **Email support**: it has the versions, settings and checks needed to reproduce it.
