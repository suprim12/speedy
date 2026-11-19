# API

Clear or rebuild pages over HTTP, from a deploy pipeline, another system that changes your data, or a webhook.

## Turn it on

In **Settings → Plugins → Speedy Cache → Advanced**, click **Generate a key**, or enter your own of at least 16 characters, and save. To keep the key out of project config, put it in `.env` and enter `$SPEEDY_API_KEY` instead:

```bash
# .env
SPEEDY_API_KEY="a-long-random-string"
```

Without a key, the API answers 404. The Advanced tab shows a ready-to-copy request once a key is set.

## Requests

Send `POST` requests with the key in an `Authorization: Bearer` header, or as a `key` field in the body for senders that can’t set headers (`-d "key=…"`). The key is never read from the URL, where it would end up in access logs. The answers are JSON.

```bash
# Clear every page
curl -X POST https://example.com/actions/speedy/api/clear \
  -H "Authorization: Bearer $SPEEDY_API_KEY"

# Clear some pages
curl -X POST https://example.com/actions/speedy/api/clear \
  -H "Authorization: Bearer $SPEEDY_API_KEY" \
  -d "urls[]=https://example.com/news" \
  -d "urls[]=https://example.com/"

# Clear the pages tagged with craft.speedy.tag('weather')
curl -X POST https://example.com/actions/speedy/api/clear \
  -H "Authorization: Bearer $SPEEDY_API_KEY" \
  -d "tags[]=weather"

# Rebuild every page, keeping the old copies until the new ones are ready
curl -X POST https://example.com/actions/speedy/api/rebuild \
  -H "Authorization: Bearer $SPEEDY_API_KEY"
```

| Request | Answer |
|---|---|
| `api/clear` | `{"success":true,"cleared":120}` |
| `api/clear` with `urls[]` | `{"success":true,"cleared":2}`, up to 1,000 URLs. A URL with `*`, such as `https://example.com/news/*`, clears every page matching it, up to 10 of them per request |
| `api/clear` with `tags[]` | `{"success":true,"cleared":4}`, up to 100 tags |
| `api/rebuild` | `{"success":true,"rebuilding":120}` |
| A wrong or missing key | 403 |

Each request shows in **Recent clears** on the utility page. Keep the key out of URLs, where it can end up in access logs.

## Status for uptime monitors

`GET actions/speedy/api/status` says whether caching works, for a monitor such as Oh Dear, UptimeRobot or Better Stack. It answers **200** while all is well, and **503** with the problems when something needs fixing, so a monitor can go by the status code alone.

```json
{"ok":false,"problems":["Speedy’s queue jobs have waited 25 minutes, so the queue isn’t running. …"],"pages":1840,"enabled":true,"checked":"2026-11-19T10:00:00+00:00"}
```

The problems are the errors the utility page shows, such as a stopped queue, rebuilds that keep failing, a failed CDN clear, a full disk or another page cache that's enabled, and caching being off.

Most monitors can't send headers, so the URL can carry a `statusToken` instead of the key: the **Advanced** tab of the settings shows the URL to copy. The token is made from the API key, can only read the status, and changes when the key does. Monitors that can send headers can use the key in an `Authorization: Bearer` header instead.

```bash
curl https://example.com/actions/speedy/api/status?statusToken=…
```

A wrong token gives 403, and with no API key set, the endpoint isn't there (404).
