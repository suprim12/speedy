# Privacy and Data

What Speedy Cache keeps and sends on **your** site. For this documentation website, see the [Privacy Policy](/privacy).

## What Speedy stores

Nothing goes in your database. With files, the default:

| Where | What |
|---|---|
| `web/speedy/` | Cached pages, with a compressed copy of each: the HTML that logged-out visitors see |
| `storage/speedy/meta/` | For each page: its URL, Craft’s cache tags, when it expires and headers to send again (such as `X-Robots-Tag`) |
| `storage/speedy/tags/`, `expiry/` | Which pages use each tag, and which expire when |
| `storage/speedy/log.jsonl` | Recent clears: what was saved (such as “Saved entry ‘Summer Sale’”), how many pages and which URLs. The last 1,000 |
| `storage/speedy/cdn.json`, `state.json` | The CDN Speedy last saw, CDN results, the CloudFront path count and when scheduled pages were checked |
| `storage/logs/speedy.log` | Warnings and errors, rotated daily, the last five days |

With **Craft’s cache** as storage, the same things are kept in that cache (such as Redis) instead, and expire after 30 days without a rebuild.

## Personal data

- **Only pages for logged-out visitors are cached,** so cached pages show what any visitor would see.
- **Pages that might be personal aren’t cached:** pages for logged-in visitors, visitors with a cart or with something in their session, pages that set cookies, pages that send `Vary`, Ajax requests and pages marked private.
- **Dynamic parts** (`craft.speedy.dynamic()`) are built for each visitor when the page loads and never stored. Their placeholders contain the template name and the variables you passed, so never pass anything private.
- **CSRF tokens are never stored.** Each visitor gets their own as the page loads.
- **The logs** contain entry titles, page URLs and the name of the control panel user who cleared pages, never visitor information. Speedy doesn’t log visits.

## Cookies

Speedy sets one cookie, and only when needed:

| Cookie | Why | How long |
|---|---|---|
| `speedy_state` | Set while a visitor’s session holds something, such as a form step or a flash message, so they get their own pages instead of cached ones. Contains no personal data | Until the session is empty again, or the browser closes |

It’s strictly necessary for the site to work, so it doesn’t need consent under the GDPR or the ePrivacy rules. List it in your cookie policy all the same.

## What’s sent to other services

- **Cloudflare and CloudFront,** only when you add their details: the URLs of pages to clear. Nothing else.
- **Rebuilding and the server check** request pages on your own site.
- Nothing is sent to us, the developer. Speedy has no tracking or telemetry.

## Keys

- Keep CDN tokens, AWS keys and the API key in `.env`, so they’re not saved in project config.
- Give CDN keys only the permission Speedy needs: **Cache Purge** for Cloudflare, `cloudfront:CreateInvalidation` for CloudFront.
- The API key is only accepted in a header or the POST body, never the URL, so it stays out of access logs.
