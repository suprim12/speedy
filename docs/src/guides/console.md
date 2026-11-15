# Console Commands

| Command | What it does |
|---|---|
| `php craft speedy/clear` | Clears every page and your CDN, then warms your `warm` list |
| `php craft speedy/clear --url=https://example.com/news` | Clears one page |
| `php craft speedy/warm` | Builds the pages in your `warm` list, with progress |
| `php craft speedy/expire` | Clears pages whose scheduled content has gone live or expired. For cron |
| `php craft speedy/status` | Pages cached, size, CDN and the last ten clears |
| `php craft speedy/server-rules` | Prints the nginx rules for this site |
| `php craft speedy/server-rules --apache` | Prints the Apache rules |

`speedy/status` looks like this:

```
Caching:      on
Pages cached: 1,284 (38.2 MB)
CDN detected: cloudfront
CDN clearing: cloudfront
CloudFront:   212 paths this month

Recent clears:
  2026-11-15 09:12  3 pages  Saved entry “Summer Sale”
  2026-11-15 08:40  1284 pages  Cleared from the command line
```
