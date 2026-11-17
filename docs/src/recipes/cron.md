# Cron and Queue

Speedy does its work in Craft’s queue: clearing, rebuilding, warming and clearing your CDN. The page that saved content returns straight away.

## The queue

By default, Craft runs queue jobs when someone has the control panel open. That’s fine for small sites. For busy ones, run the queue all the time instead:

```bash
php craft queue/listen
```

with Supervisor, systemd or your host’s worker setting, and set `runQueueAutomatically` to `false` in `config/general.php`.

### If the queue stops

When a site’s queue stops, most page caches stop clearing and visitors see old content, with no error anywhere. Speedy notices when its jobs have waited more than ten minutes, and then:

- clears pages straight away when content changes, so changes still show. They’re rebuilt on their next visit instead of in the background;
- builds expired pages for their next visitor instead of waiting for the queue;
- shows an alert across the control panel, and an error on the utility page, until the queue runs again.

When the control panel runs the queue (Craft’s default `runQueueAutomatically`), jobs simply wait while nobody has it open, which is normal on a quiet site. The alert then only shows if the queue still hasn’t run ten minutes after someone opens the control panel.

Set `clearInRequest` to `true` to always clear straight away, such as on a small site with no queue runner, or `false` to always wait for the queue.

## Cron

Scheduled content (an entry going live at 9 am) is handled by a delayed queue job. For exact timing, add one line to cron too:

```bash
* * * * * php /path/to/craft speedy/expire > /dev/null 2>&1
```

It does nothing when nothing has expired. It also deletes pages left over from **Clear all** that the queue hasn’t deleted yet, so a stopped queue can’t fill the disk.

## Craft Cloud and managed hosts

Add `php craft speedy/expire` as a scheduled command, every minute or every five.
