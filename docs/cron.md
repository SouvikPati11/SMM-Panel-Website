# Cron jobs

Shared hosting has no background workers, so all periodic work is done by
short PHP scripts started by cron. Every task runs under a MySQL named lock
(`GET_LOCK`), so overlapping invocations never run the same task twice, and each
run is recorded in `cron_runs` (Admin → **Cron tasks**, where you can also run a
task manually).

| Task | Interval | Does |
|---|---|---|
| `orders` | 3 min | recover stuck submissions → submit queued orders → sync statuses (partial/cancel refunds) |
| `payments` | 5 min | verify pending gateway invoices (missed webhooks), expire stale invoices |
| `refills` | 15 min | sync refill statuses |
| `notifications` | 1 min | send queued emails |
| `provider_sync` | 6 h | provider balances, catalogs, auto-synced prices, disable removed services |
| `cleanup` | daily | prune old logs, rate-limit rows, expired tokens, old log files |

## Recommended: one line

`cron/run.php` runs every task whose interval has elapsed.

**cPanel** → Advanced → Cron Jobs → Common settings "Once per minute":

```
* * * * * /usr/local/bin/php /home/CPANELUSER/public_html/cron/run.php >/dev/null 2>&1
```

**Hostinger (hPanel)** → Advanced → Cron Jobs → "Custom", command:

```
/usr/bin/php /home/u123456789/domains/example.com/public_html/cron/run.php
```
with schedule `* * * * *` (or `*/5 * * * *` if your plan's minimum is 5 minutes).

Find the right PHP binary and path:
- cPanel: `/usr/local/bin/php`, or a version-specific one such as `/opt/cpanel/ea-php82/root/usr/bin/php`.
- CloudLinux/alt-php: `/opt/alt/php82/usr/bin/php`.
- Hostinger: `/usr/bin/php` (or `/opt/alt/php82/usr/bin/php`).
- The exact line for *your* server is shown in Admin → Cron tasks.

Use the same PHP version as the website (8.1+), otherwise the script exits with an error.

## Alternative: one line per task

```
*/3  * * * * /usr/local/bin/php /home/USER/public_html/cron/orders.php        >/dev/null 2>&1
*/5  * * * * /usr/local/bin/php /home/USER/public_html/cron/payments.php      >/dev/null 2>&1
*/15 * * * * /usr/local/bin/php /home/USER/public_html/cron/refills.php       >/dev/null 2>&1
*    * * * * /usr/local/bin/php /home/USER/public_html/cron/notifications.php >/dev/null 2>&1
0 */6 * * *  /usr/local/bin/php /home/USER/public_html/cron/provider-sync.php >/dev/null 2>&1
30 3 * * *   /usr/local/bin/php /home/USER/public_html/cron/cleanup.php       >/dev/null 2>&1
```

Do not combine both styles.

## URL-only cron (last resort)

If your host can only fetch URLs, set `CRON_KEY` in `.env` (32+ random
characters — the installer generates one) and schedule:

```
* * * * * curl -fsS https://example.com/tasks/run/YOUR_CRON_KEY >/dev/null
```

Wrong keys return 404; the endpoint is also rate limited. CLI cron is preferred
(no web timeouts).

## Verifying

- Admin → Dashboard shows a red banner if any task has not run recently.
- Admin → System health / Cron tasks show last run, duration and output.
- Manually: `php cron/run.php` over SSH prints each task's result.
- Scripts refuse to run over HTTP (`cron/` is also blocked by `.htaccess`).
