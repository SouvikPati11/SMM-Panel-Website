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

## URL-only cron (last resort)

If your host can only fetch URLs, set `CRON_KEY` in `.env` (32+ random
characters; the installer generates one) and schedule:

```
* * * * * curl -fsS https://example.com/tasks/run/YOUR_CRON_KEY
```

Wrong keys return 404, and the endpoint is rate limited. It returns HTTP 500
when a task failed and records into the same diagnostics. CLI cron is preferred
(no web time limits).
