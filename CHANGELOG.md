# Release Notes for Speedy Cache

## 1.0.0 - Unreleased

### Added
- Full-page caching of logged-out GET pages as static HTML files, with no database tables.
- Pages are served by the web server without PHP, with generated Nginx and Apache rules and a live check, or by PHP when the rules aren’t added.
- Clearing based on Craft’s own cache tags: saving an entry clears the pages that show it and the pages that list its section.
- One clear job per request, queue job or bulk operation, such as an import.
- Drafts, autosaves and revisions never clear live pages.
- Cleared pages are rebuilt in the background before the old copy is replaced.
- Pages clear when scheduled content goes live or expires.
- Forms keep working on cached pages: CSRF tokens are refreshed automatically.
- Logged-in visitors, Commerce carts, pages that set cookies and pages with query strings aren’t cached.
- Cloudflare and CloudFront: detected automatically and cleared when pages change, with CloudFront paths batched into wildcards and a monthly counter.
- CDNs are told not to keep pages that Speedy can’t clear from them.
- Warming from the sitemap or a list of URLs.
- Utilities → Speedy Cache, with stats, clear actions, a server check, CDN tests and a log of what was cleared and why.
- `craft.speedy.skip()` and `craft.speedy.ttl()` for templates.
- Cached pages end with a comment saying when they were cached (`cacheComment`).
- Console commands: `speedy/clear`, `speedy/warm`, `speedy/expire`, `speedy/status` and `speedy/server-rules`.
