# Privacy and Data

## What Speedy stores

Everything is in files, not the database:

| Where | What |
|---|---|
| `web/speedy/` | Cached pages: the HTML that logged-out visitors see |
| `storage/speedy/meta/` | For each page: its URL, Craft’s cache tags and when it expires |
| `storage/speedy/tags/` | For each tag: the pages that use it |
| `storage/speedy/log.jsonl` | Recent clears: what was saved (such as “Saved entry ‘Summer Sale’”), how many pages and which URLs |
| `storage/speedy/cdn.json` | The CDN Speedy last saw, CDN results and the CloudFront path count |

## Personal data

- **Only pages for logged-out visitors are cached,** so cached pages show what any visitor would see.
- **Pages that might be personal aren’t cached:** pages for logged-in visitors, visitors with a cart or with something in their session, pages that set cookies, and pages marked private.
- **One cookie, only when needed:** `speedy_state` is set while a visitor’s session holds something, such as a form step, so they get their own pages. It contains no personal data, is removed once the session is empty, and is strictly necessary for the site to work, so it needs no consent.
- **CSRF tokens are never stored.** Each visitor gets their own as the page loads.
- **The log** contains entry titles and page URLs, never visitor information.

## What’s sent to other services

- **Cloudflare and CloudFront,** only when you add their details: the URLs of pages to clear. Nothing else.
- **Warming and the server check** request pages on your own site.

## Keys

Keep CDN tokens and keys in `.env`, and give them only the permission Speedy needs: **Cache Purge** for Cloudflare, `cloudfront:CreateInvalidation` for CloudFront.
