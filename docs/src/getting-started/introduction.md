# Introduction

Speedy Cache saves your Craft pages as static HTML files and sends those to visitors instead of building each page again. When content changes, it clears only the pages that show it, and rebuilds them in the background.

![Utilities → Speedy Cache: 16 pages cached, buttons to clear pages and check the server, and recent clears such as Saved entry “Adelaide CBD Store”, 7 pages](/images/utility.jpg)

## What makes it different

- **No database tables.** Pages, and what each one depends on, are files. Your database doesn’t grow, however many pages or visits you have.
- **Clearing you can trust.** Speedy uses Craft’s own cache tags, the same system as Craft’s `{% cache %}` tag. Saving an entry clears the pages that show it and the pages that list its section, and nothing else.
- **No template changes.** Forms keep working: CSRF tokens are refreshed as the page loads. Logged-in visitors, carts and pages that set cookies are never cached.
- **Served without PHP.** Add the rules Speedy writes for Nginx or Apache, and cached pages are sent by the web server in a millisecond or two.
- **Cloudflare, CloudFront, Fastly, Bunny, KeyCDN and Varnish built in.** Speedy spots your CDN and clears it too, and other plugins can add more.
- **Plain-English log.** “Saved entry ‘Summer Sale’ → 12 pages”, so you can always see why a page changed.

## How a page is cached

1. A logged-out visitor opens a page. Craft builds it as usual, and Speedy records which entries, sections, assets and globals it used.
2. Speedy saves the HTML. The next visitor gets the saved copy.
3. An editor saves an entry. Craft tells Speedy which tags changed, and Speedy finds the pages that use them.
4. Those pages are rebuilt in the background, and the new copy replaces the old one. Visitors never wait.

## Next steps

- [Install Speedy](/getting-started/installation)
- [Serve pages without PHP](/getting-started/server-rules)
- [How clearing works](/guides/clearing)
