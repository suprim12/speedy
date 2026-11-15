# Scheduled Content

An entry with a post date in the future, or an expiry date, changes the site at that moment without anyone saving anything. Speedy knows when.

While a page is built, Craft notes the soonest moment any element on it goes live or expires. Speedy saves that time with the page, and clears and rebuilds the page when it comes.

For example, `/news` lists News entries. An entry is scheduled for 9 am tomorrow, so `/news` is cleared at 9 am and rebuilt with it.

## How it’s run

Speedy queues a delayed job for the next moment a page expires. That works with no setup, as long as your queue runs.

For exact timing on busy sites, add the cron command too. It clears whatever has expired, then stops:

```bash
* * * * * php /path/to/craft speedy/expire
```

See [Cron and queue](/recipes/cron).

## Time-limited pages

For pages that should be rebuilt on a timer, such as one showing the weather, see [Pages with live data](/recipes/live-data).
