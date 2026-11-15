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
| `api/clear` with `urls[]` | `{"success":true,"cleared":2}`, up to 1,000 URLs |
| `api/clear` with `tags[]` | `{"success":true,"cleared":4}`, up to 100 tags |
| `api/rebuild` | `{"success":true,"rebuilding":120}` |
| A wrong or missing key | 403 |

Each request shows in **Recent clears** on the utility page. Keep the key out of URLs, where it can end up in access logs.
