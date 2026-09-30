# Cron jobs

Shared hosting has no background workers, so all periodic work is done by
short PHP runs started by the hosting control panel's cron. Every task runs
under a MySQL named lock (`GET_LOCK`), so overlapping invocations never run the
same task twice, and each run is recorded in `cron_runs`. Admin → **Cron tasks**
shows the last run, failures and diagnostics, and can start a task manually.

| Task | Interval | Does |
|---|---|---|
| `orders` | 3 min | recover stuck submissions → submit queued orders → sync statuses (partial/cancel refunds) |
| `subscriptions` | 1 min | place the next order of each due auto-subscription (retries with back-off) |
| `payments` | 5 min | verify pending gateway invoices (missed webhooks), expire stale invoices |
| `refills` | 15 min | sync refill statuses |
| `notifications` | 1 min | send queued emails |
| `provider_sync` | 6 h | provider balances, catalogs, auto-synced prices, disable removed services |
| `cleanup` | daily | prune old logs, rate-limit rows, expired tokens, old log files |

## The one cron job you need

`cron/run.php` runs every task whose interval has elapsed. Schedule it **every
minute** (or every 5 minutes if your plan's minimum is 5).

**Use the PHP CLI binary.** Admin → Cron tasks shows the exact command for your
server. Do **not** copy the PHP path shown by the website's own PHP (on
LiteSpeed hosting that is `lsphp`, a web binary).

### Hostinger (hPanel)

hPanel → **Advanced → Cron Jobs** → type **Custom** → command:

```
/usr/bin/php /home/u123456789/domains/example.com/public_html/cron/run.php
```

Schedule: every minute (`* * * * *`). If `/usr/bin/php` is not the same PHP
version as your website (hPanel → Advanced → PHP Configuration), use the
version-specific CLI, e.g. `/opt/alt/php83/usr/bin/php`. Use **View output** on
the cron job to see each run's result line or error.

### cPanel

Advanced → **Cron Jobs** → "Once per minute":

```
/usr/local/bin/php /home/CPANELUSER/public_html/cron/run.php
```

Version-specific CLIs: `/opt/cpanel/ea-php83/root/usr/bin/php`,
`/opt/alt/php83/usr/bin/php` (CloudLinux).

### Why not `>/dev/null 2>&1`?

Earlier versions suggested silencing cron. That hid real failures: on
LiteSpeed hosts the suggested binary was `lsphp`, the script treated it as an
HTTP request and exited with status 0 without running anything. Now:

- each run prints **one line** (`[… UTC] cron ok: orders=success, …`);
- errors go to **stderr** (Hostinger "View output", cPanel cron email) and to
  `storage/logs/cron-YYYY-MM-DD.log`;
- every invocation is recorded in `storage/cron-status.json`, shown in Admin →
  Cron tasks, even when it fails before reaching the database.

If you do not want cron email on cPanel, set the cron email address to empty
rather than discarding output.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | all due tasks succeeded (or none were due; tasks locked by another run are skipped) |
| 1 | at least one task failed; see the error line and Admin → Cron tasks |
| 2 | environment/configuration problem: PHP older than 8.1, missing extensions, not installed, `.env` not readable by the user cron runs as (run cron as the account that owns the site files) |
| 3 | database unavailable (credentials in `.env`, MySQL down) |

## Diagnostics — verify cron after deployment

1. **Admin → Cron tasks** — "Last cron run" shows when cron last ran, with
   which PHP binary/version/SAPI, the exit code, the last success and the last
   error. Warnings appear when cron has never run, has not run for 15 minutes,
   runs a different PHP version than the website, or runs through `lsphp`.
2. **`--check`** (SSH, or a one-off cron job whose output you view):

   ```
   /usr/bin/php /home/…/public_html/cron/run.php --check
   ```

   It checks the PHP version and extensions *of the binary cron uses*,
   writable storage directories, installed state, the database connection,
   PHP-vs-database clock skew, named locks and the `cron_runs` table, and lists
   each task's schedule and last result. It runs no task. Its result is shown
   in Admin → Cron tasks as "Last --check".
3. Other options: `--list` (tasks and last results), `--task=orders` (run one
   task now), `--force` (run all tasks now), `-v` (print each task's output).

## How it works

- `cron/bootstrap.php` works with any working directory (all paths are
  absolute, and it `chdir`s to the project root). It accepts the PHP CLI and web
  binaries started from cron (`lsphp`/`php-cgi` report a non-`cli` SAPI; an
  HTTP request is recognised by `REQUEST_METHOD` instead) and refuses HTTP
  (`cron/` is also blocked by `.htaccess`).
- A task that throws is marked `failed` with its error; the remaining tasks
  still run. A task killed by a PHP fatal error/timeout is recorded as failed
  by a shutdown handler; a run left `running` by a killed process is marked
  failed at the task's next run.
- `run.php` re-checks each task's schedule after acquiring its lock, so two
  overlapping schedulers never run the same task back-to-back.

## Alternative: one line per task

```
*/3  * * * * /usr/bin/php /home/USER/public_html/cron/orders.php
*/5  * * * * /usr/bin/php /home/USER/public_html/cron/payments.php
*/15 * * * * /usr/bin/php /home/USER/public_html/cron/refills.php
*    * * * * /usr/bin/php /home/USER/public_html/cron/notifications.php
*    * * * * /usr/bin/php /home/USER/public_html/cron/subscriptions.php
0 */6 * * *  /usr/bin/php /home/USER/public_html/cron/provider-sync.php
30 3 * * *   /usr/bin/php /home/USER/public_html/cron/cleanup.php
```

Do not combine both styles.

## Cron URL (Option B)

Use this when your host can only call a URL, or the PHP command does not work.
**Admin → Cron → Option B** shows the exact, working URL and ready-to-paste
commands. If there is no URL yet, press **Create cron URL**: a random key is
generated and stored encrypted. `CRON_KEY` in `.env` (32+ characters), when
set, takes precedence. **Generate a new URL** rotates the key, and the old
URL stops working immediately.

Hostinger's *Custom* cron job runs a **shell command**, so a bare URL pasted
there never runs anything. Paste the `wget` command from the admin page:

```
* * * * * wget -q -O - --timeout=600 'https://example.com/tasks/run/YOUR_KEY'
# or
* * * * * curl -fsS -L --max-time 600 'https://example.com/tasks/run/YOUR_KEY'
```

Both follow redirects (http→https, www), so a site that forces HTTPS still
runs its tasks. (Plain `curl` without `-L` silently stops at the redirect.)

What the URL does:

- It runs every **due** task, exactly like `php cron/run.php`, with the same
  per-task locks, so it can safely run alongside the CLI job.
- It answers with a one-line JSON summary, for example
  `{"ok":true,"ran":{"subscriptions":"success"},"message":"Ran 1 due task(s)."}`.
  When nothing is due it answers "No task was due". It returns HTTP 500 if a
  task failed.
- Responses are marked `no-store` and `X-LiteSpeed-Cache-Control: no-cache`, so
  LiteSpeed/CDN caches never answer in place of running the tasks.
- It keeps running if the caller disconnects (`ignore_user_abort`) and allows up
  to 10 minutes.
- A wrong or old key answers 404 and does nothing. The admin Cron page then
  warns "A cron URL with a wrong or old key was called…", so an outdated job is
  easy to spot.
- It is rate limited (3 calls per 50 seconds).
- Admin → Cron shows *Called by* (Cron URL or PHP command), *Last URL call*
  (time, HTTP status, IP) and the last rejected call.

The older path `/cron/run/KEY` mentioned in earlier code comments never
worked on Hostinger, because the root `.htaccess` blocks everything under
`/cron/` (403). Use `/tasks/run/KEY`.
