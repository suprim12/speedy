# Scheduled Content

An entry with a post date in the future, or an expiry date, changes the site at that moment without anyone saving anything. Speedy knows when.

**Expiring.** While a page is built, Craft notes the soonest moment any element on it expires. Speedy saves that time with the page, and clears and rebuilds the page when it comes. A News entry that expires at 5 pm leaves `/news` at 5 pm.

**Going live.** An entry that isn’t live yet isn’t on any page, so no page knows about it. Speedy watches entries and Commerce products with future post dates instead, and when one goes live it clears the pages that list its section. An entry scheduled for 9 am tomorrow appears on `/news` at 9 am.

If a visitor arrives in the moment between the page expiring and its rebuild, they get the old copy (`X-Speedy: STALE`) while the new one is built in the background, so nobody waits. After 10 minutes, an expired page is built fresh instead.

## How it’s run

Speedy queues a delayed job for the next moment a page expires. That works with no setup, as long as your queue runs.

For exact timing on busy sites, add the cron command too. It clears whatever has expired, then stops:

```bash
* * * * * php /path/to/craft speedy/expire
```

See [Cron and queue](/recipes/cron).

## Rebuild every day

Some pages show dates without any entry changing, such as “upcoming events”, “open today” or “this week’s specials”. List them under **Rebuild every day** on the settings page, with the time, and Speedy rebuilds them each day at that time, in the site’s time zone. Visitors get the old copies until the new ones are ready.

```php
// config/speedy.php
'dailyRebuild' => ['events*', '/'],
'dailyRebuildTime' => '00:05',
```

Paths match like **Never cache**: `events*` matches `/events`, `/events/today` and `/events-archive`, and `/` is the homepage. Only pages that are cached are rebuilt; the rest are built fresh when they’re visited.

It runs with the scheduled job above, so the queue needs to run. If it was stopped at that time, the pages are rebuilt as soon as it runs again, once. When you first set it up, the first rebuild is at the next time, not straight away.

## Lifetimes by section

For the entries of a section that show something Craft doesn’t know has changed, such as prices from another system, choose a lifetime under **Lifetimes by section** on the settings page. Their pages are rebuilt after that long, and still cleared straight away when they change.

```php
// config/speedy.php, in seconds
'sectionLifetimes' => ['news' => 3600, 'weather' => 300],
```

It’s for each entry’s own page. A listing that shows the section, such as a news index, keeps its own lifetime: set one in its template with `craft.speedy.ttl()`. The shortest of a page’s lifetimes wins, with `defaultTtl` for every page.

## Time-limited pages

For pages that should be rebuilt on a timer, such as one showing the weather, see [Pages with live data](/recipes/live-data).
