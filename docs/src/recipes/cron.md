# Cron and Queue

Speedy does its work in Craft’s queue: clearing, rebuilding, warming and clearing your CDN. The page that saved content returns straight away.

## The queue

By default, Craft runs queue jobs when someone has the control panel open. That’s fine for small sites. For busy ones, run the queue all the time instead:

```bash
php craft queue/listen
```

with Supervisor, systemd or your host’s worker setting, and set `runQueueAutomatically` to `false` in `config/general.php`.

## Cron

Scheduled content (an entry going live at 9 am) is handled by a delayed queue job. For exact timing, add one line to cron too:

```bash
* * * * * php /path/to/craft speedy/expire > /dev/null 2>&1
```

It does nothing when nothing has expired.

## Craft Cloud and managed hosts

Add `php craft speedy/expire` as a scheduled command, every minute or every five.
