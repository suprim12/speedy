# Console Commands

| Command | What it does |
|---|---|
| `php craft speedy/clear` | Clears every page and your CDN, then warms your `warm` list |
| `php craft speedy/clear --rebuild` | Rebuilds every page in the background, keeping the old copies until the new ones are ready |
| `php craft speedy/clear --url=https://example.com/news` | Clears one page, with its query string versions |
| `php craft speedy/clear --url="https://example.com/news/*"` | Clears every page matching the pattern. A path, such as `"/news/*"`, matches every domain. Put it in quotes, so the shell doesn’t expand `*` |
| `php craft speedy/clear --site=german` | Clears every page of one site, by its handle |
| `php craft speedy/clear --tag=weather` | Clears the pages tagged with `craft.speedy.tag('weather')`. Several, separated by commas |
| `php craft speedy/migrate` | Shows what another page cache plugin’s settings and template calls become in Speedy. Add `--apply` to save the settings ([Switching from another page cache](/guides/switching)) |
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
