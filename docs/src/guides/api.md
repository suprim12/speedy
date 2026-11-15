# API

Clear or rebuild pages over HTTP, from a deploy pipeline, another system that changes your data, or a webhook.

## Turn it on

Set a key of at least 16 characters, kept in `.env`:

```bash
# .env
SPEEDY_API_KEY="a-long-random-string"
```

```php
// config/speedy.php
'apiKey' => '$SPEEDY_API_KEY',
```

Without a key, the API answers 404.

## Requests

Send `POST` requests with the key in an `Authorization` header. The answers are JSON.

```bash
# Clear every page
curl -X POST https://example.com/actions/speedy/api/clear \
  -H "Authorization: Bearer $SPEEDY_API_KEY"

# Clear some pages
curl -X POST https://example.com/actions/speedy/api/clear \
  -H "Authorization: Bearer $SPEEDY_API_KEY" \
  -d "urls[]=https://example.com/news" \
  -d "urls[]=https://example.com/"

# Rebuild every page, keeping the old copies until the new ones are ready
curl -X POST https://example.com/actions/speedy/api/rebuild \
  -H "Authorization: Bearer $SPEEDY_API_KEY"
```

| Request | Answer |
|---|---|
| `api/clear` | `{"success":true,"cleared":120}` |
| `api/clear` with `urls[]` | `{"success":true,"cleared":2}`, up to 1,000 URLs |
| `api/rebuild` | `{"success":true,"rebuilding":120}` |
| A wrong or missing key | 403 |

Each request shows in **Recent clears** on the utility page. Keep the key out of URLs, where it can end up in access logs.
