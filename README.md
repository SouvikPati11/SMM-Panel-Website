# SMM Panel

A self-hosted social-media-marketing reseller panel for **PHP 8.1+ and
MySQL/MariaDB on ordinary shared hosting** (Hostinger, cPanel, any Apache
host). No Node.js, no Redis, no workers, no Docker — upload, run the web
installer, add one cron line.

- Customer panel: dashboard, live-priced order form, mass orders, order tracking,
  refills, cancellations, wallet with full ledger, crypto & manual top-ups,
  promo codes, affiliate program, support tickets, reseller API keys, 2FA.
- Admin panel: users & balances, orders (with safe resolution of ambiguous
  provider responses), services & categories, multiple SMM providers with
  catalog import and automatic price sync, payments & manual approvals,
  gateways, coupons, affiliates, announcements & notifications, pages, FAQ,
  blog, SEO, settings, roles & permissions, logs & audit trail, system health,
  cron tasks.
- **Auto-subscriptions** (orders repeated on a schedule by cron), **display
  currencies**, **price levels by lifetime deposits**, configurable registration
  (mobile number, email verification), audited balance add/remove and
  lifecycle-checked order status changes. See [`docs/features.md`](docs/features.md).
- Standard **SMM API v2** for resellers (compatible with common panel scripts).
- Payment gateways: **OxaPay** and **Cryptomus** (implemented against the
  vendors' official SDKs), **P2Gateway.in UPI** (implemented against the
  merchant API documentation; callbacks verified through the status API),
  and **manual** methods (UPI/bank/crypto address with QR + proof upload). None
  has been tested with a live payment yet (see [Known limitations](#known-limitations)).

Design and research notes: [`docs/architecture.md`](docs/architecture.md).

---

## Contents
1. [Requirements](#requirements)
2. [Installation (Hostinger / cPanel)](#installation)
3. [Configuration](#configuration)
4. [Database](#database)
5. [Admin login](#admin-login)
6. [Payment gateways](#payment-gateways)
7. [Providers](#providers)
8. [Cron](#cron)
9. [Webhooks](#webhooks)
10. [Reseller API](#reseller-api)
11. [Project structure](#project-structure)
12. [Architecture overview](#architecture-overview)
13. [Feature list](#feature-list)
14. [Testing](#testing)
15. [Security checklist](#security-checklist)
16. [Troubleshooting](#troubleshooting)
17. [Known limitations](#known-limitations)

---

## Requirements

| | |
|---|---|
| PHP | **8.1 – 8.4** |
| Required extensions | `pdo_mysql`, `mbstring`, `json`, `curl`, `openssl`, `fileinfo`, `sodium`, `ctype`, `dom` (all enabled by default on Hostinger/cPanel) |
| Recommended | `bcmath` (a pure-PHP exact fallback is built in), `gd` (image re-encoding), `intl` |
| Database | MySQL 5.7+/8.x or MariaDB 10.3+ (InnoDB, utf8mb4) |
| Web server | Apache (or LiteSpeed) with `mod_rewrite` and `.htaccess` allowed |
| Other | HTTPS certificate (required for payment webhooks), cron access |

Composer is **optional**: the app has its own autoloader and no third-party runtime dependencies.
The PHP version and extensions above are enforced by the application on every
request and CLI run (`app/Core/Requirements.php`). If one is missing, the site
shows a plain 500 message naming it, and the installer lists it as well. They
are deliberately **not** declared as Composer platform requirements.

**Hostinger Git deployment.** Hostinger runs `composer install` automatically
after each Git pull, using its CLI PHP, which can have different extensions
from the web PHP. The committed `composer.lock` has no packages, so that step
installs nothing, needs no network and cannot fail on platform requirements.
It only generates `vendor/autoload.php`, which the app uses if present.
`vendor/` is never required, never committed, and is blocked from the web.
Deploying without Composer (plain upload, FTP, or Git without the Composer
step) works the same way.

---

## Installation

### 1. Create a MySQL database
- **Hostinger**: hPanel → Websites → Manage → Databases → *MySQL Databases* → create database + user (note the `u123_` prefixes).
- **cPanel**: *MySQL® Databases* → create database, create user, **Add user to database** with *ALL PRIVILEGES*.

### 2. Deploy the files
Two layouts are supported, and both work without moving files after a deploy:

- **A. Repository root = web root (Hostinger Git default).** hPanel →
  Websites → Manage → **Advanced → Git** → repository + branch, and leave
  *Install path* empty so the site deploys into `public_html`. Every push then
  deploys directly and the site works.
  - The root `.htaccess` serves `public/assets` and `public/uploads` as `/assets/…` and `/uploads/…`.
  - Every other request goes to the root `index.php` front controller, which loads `public/index.php`.
  - It returns 403 for `.env`, `.git`, `app/`, `config/`, `storage/`, `cron/`, `database/`, `routes/`, `resources/`, `tests/`, `docs/`, `vendor/`, dotfiles, `*.md`, `*.json`, `*.lock` and any script in `uploads/`.
  - It is single-pass: LiteSpeed (Hostinger) does not re-run the rewrite rules of a subdirectory after a rewrite, so the configuration does not rely on `public/.htaccess`.
- **B. Document root = `public/` (recommended where the host allows it).**
  Set the domain's document root to `…/public` (cPanel → Domains → Manage →
  *Document Root*). Nothing outside `public/` is then reachable at all, and
  `public/.htaccess` handles routing.

For a manual upload instead of Git, use File Manager → Upload ZIP → Extract into
`public_html`, then make sure hidden files (`.htaccess`) were extracted.

### 3. Select the PHP version
Hostinger: *Advanced → PHP Configuration* → 8.2/8.3. cPanel: *Select PHP Version* / *MultiPHP Manager* → 8.2+. Enable the extensions listed above if any are off.

### 4. Permissions
Directories `755`, files `644`. PHP must be able to write to `storage/` (and its subfolders) and `public/uploads/`. The installer checks this.

### 5. Run the installer
Open `https://your-domain/install`:
1. **Requirements** — fix anything red.
2. **Database** — enter host (usually `localhost`), name, user, password. The installer refuses a database that already contains panel tables.
3. **Site & admin** — site name, URL, a **custom admin path**, and your super-admin account.

The installer creates all tables, seeds roles/permissions, settings, legal page
templates, FAQ and payment methods, writes `.env` with a fresh `APP_KEY`, and
creates `storage/installed.lock`. From then on `/install` returns 404.
If `.env` isn't writable, the final screen shows its contents to create manually.

### 6. SSL
Enable the free SSL certificate (Hostinger → SSL; cPanel → *SSL/TLS Status* → AutoSSL). Keep `FORCE_HTTPS=true` in `.env`.

### 7. Cron
Add **one** cron job — see [Cron](#cron).

### 8. Post-install
Admin panel → **Email** (SMTP + test), **Payment gateways**, **Providers**,
**Settings** (currency, timezone, referral %, limits), **Pages** (edit Terms /
Privacy / Refund policy), **SEO**; enable **2FA** in *My account*. Then check
*System health*. Full checklist: [`docs/security.md`](docs/security.md).

### Manual installation (without the web installer)
```bash
cp .env.example .env    # fill in DB_* and APP_URL
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # put into APP_KEY
mysql -u USER -p DBNAME < database/schema.sql
php -r 'require "app/bootstrap.php"; App\Install\Seeder::run();'
# create the first admin:
php -r 'require "app/bootstrap.php"; $d=App\Core\Database::instance(); $id=$d->insert("admins",["username"=>"admin","email"=>"you@example.com","password_hash"=>password_hash("CHANGE-ME-123",PASSWORD_DEFAULT),"status"=>"active","is_super"=>1,"created_at"=>now(),"updated_at"=>now()]); echo "admin #$id\n";'
touch storage/installed.lock
```

---

## Configuration

`.env` (see [`.env.example`](.env.example)) holds only what must exist before
the database is reachable: `APP_URL`, `APP_KEY`, `APP_DEBUG`, `ADMIN_PATH`,
`FORCE_HTTPS`, `TRUSTED_PROXIES`, `DB_*`, session options, fallback `MAIL_*`,
`UPLOAD_MAX_BYTES`, `CRON_KEY`. Everything else is edited in **Admin →
Settings / Email / SEO / Payment gateways / Providers** and stored in the
`settings` table (secrets encrypted).

> ⚠️ **Back up `APP_KEY`.** Provider keys, gateway credentials, the SMTP
> password and 2FA secrets are encrypted with it. Changing or losing it makes
> them unreadable (you would have to re-enter them).

All timestamps are stored in **UTC**; the display timezone is a site setting
and each user can pick their own.

---

## Database

The authoritative schema is [`database/schema.sql`](database/schema.sql)
(46 InnoDB tables with foreign keys and indexes). Highlights:

| Table | Purpose |
|---|---|
| `users`, `wallets`, `price_levels` | accounts, balances (`DECIMAL(18,6)`), discount tiers |
| `transactions` | immutable ledger; unique `reference` makes every financial event idempotent |
| `orders`, `order_logs`, `refills` | orders with `submit_state` (queued/submitting/submitted/unknown/manual/failed), history, refills |
| `services`, `categories`, `providers`, `provider_services` | catalog and cached provider catalogs |
| `payments`, `payment_methods`, `manual_payment_requests` | deposits; unique `(gateway, gateway_ref)` |
| `coupons`, `coupon_usage`, `referrals`, `referral_transactions` | promotions and affiliates (unique per payment) |
| `tickets`, `ticket_messages`, `ticket_attachments` | support |
| `admins`, `roles`, `permissions`, `role_permissions`, `admin_roles` | RBAC |
| `api_keys`, `api_logs`, `provider_logs`, `webhook_logs`, `audit_logs`, `login_attempts` | security & observability |
| `rate_limits`, `email_queue`, `cron_runs`, `settings`, `notifications`, `announcements`, `pages`, `faqs`, `blog_*` | infrastructure & content |

Back up with cPanel *Backup* / Hostinger *Backups*, or `mysqldump --single-transaction`.

---

## Admin login

`https://your-domain/<ADMIN_PATH>/login` (the path you chose in the installer,
stored in `.env`). It is deliberately not linked anywhere or listed in
`robots.txt`. Admins are separate accounts from customers; create more under
**Admins & roles** and restrict them with roles (Support, Finance, Content
Editor, or custom). Lost admin password: use *Forgot password* (needs SMTP) or
update `admins.password_hash` via phpMyAdmin with a `password_hash()` value.

---

## Payment gateways

Full details: [`docs/payment-gateways.md`](docs/payment-gateways.md).

| Gateway | Status | You need |
|---|---|---|
| OxaPay | ✅ implemented (API v1) | Merchant API key |
| Cryptomus | ✅ implemented (API v1) | Merchant UUID + Payment API key |
| Manual (UPI / bank / crypto address) | ✅ implemented | Account details, optional QR image |
| P2Gateway.in (UPI) | ✅ implemented (create-order + check-order-status) — needs one live test | API token, site currency INR, webhook URL set in the P2Gateway dashboard |

Wallets are credited only after a **verified webhook plus a server-side status
query** (or the cron verifier), never from a browser redirect. P2Gateway
callbacks are unsigned, so their content is ignored apart from the order
reference; the payment state and amount always come from P2Gateway's status API,
and the amount must match exactly or the payment is held for admin review.
Held payments are handled on their own page (Admin → Payments → *Review*):
**Approve & credit**, **Reject**, or **Re-check & release**. Each decision
needs a note and is audited.

---

## Providers

Full details: [`docs/provider-api.md`](docs/provider-api.md).

Admin → Providers → *Add provider* (API URL + key, currency & exchange rate) →
*Fetch services* → *Browse & import* with a markup %. Services can auto-sync
price/min/max. Non-standard panels are handled with a JSON parameter map, and
new API styles plug in through `ProviderAdapterInterface`. Manual-fulfilment
services (no provider) are also supported.

---

## Cron

Full details: [`docs/cron.md`](docs/cron.md). Add **one** cron job, every minute, using the
PHP **CLI** binary (not `lsphp`):

```
/usr/bin/php /home/u123456789/domains/example.com/public_html/cron/run.php
```

- **Hostinger:** hPanel → Advanced → Cron Jobs → Custom. Use the same PHP version as the site, e.g. `/opt/alt/php83/usr/bin/php`. Check each run with *View output*.
- **cPanel:** Cron Jobs → "Once per minute" → `/usr/local/bin/php /home/USER/public_html/cron/run.php`.
- Don't append `>/dev/null 2>&1`. Each run prints one line, errors go to stderr, exit codes are meaningful (0 ok, 1 task failed, 2 environment, 3 database), and every run is recorded.
- **Verify:** Admin → *Cron tasks* shows the last run, the PHP binary cron used, the last success and the last error. It also shows the exact command for your server. Run `…/cron/run.php --check` to diagnose the cron PHP itself.

---

## Webhooks

| Gateway | URL |
|---|---|
| OxaPay | `https://your-domain/webhooks/oxapay` |
| Cryptomus | `https://your-domain/webhooks/cryptomus` |
| P2Gateway | `https://your-domain/webhooks/p2gateway` |

The OxaPay and Cryptomus URLs are sent automatically with every invoice, so no
dashboard setting is strictly required. **P2Gateway's URL must be entered in the
P2Gateway Merchant Dashboard → Webhook URL** (it is not sent per order). Cron
also re-checks pending payments every few minutes, so a missed callback delays
the credit but does not lose it. Every delivery is visible in Admin → Logs →
*Payment webhooks* with its signature/verification result.

## Upgrading an existing installation

Upload the new files (keep `.env`, `storage/` and `public/uploads/`), then run
the database migrations once:

```
php database/migrate.php
```

It is safe to run repeatedly and prints "Database schema is up to date." when there is
nothing to do. Migrations never delete data: they add tables and columns and only
re-derive cached values from the ledger. `2026_10_05_subscriptions_currency_levels`, for
example, adds subscriptions, display currencies, deposit thresholds and the mobile
field. It keeps admin-assigned price levels and leaves existing levels without a
threshold, so nobody is auto-promoted. Without SSH, use **Admin → System health → Apply database
upgrades**, which runs the same migrations.

---

## Reseller API

`POST https://your-domain/api/v2` with `key` and `action`
(`services`, `add`, `status` (single/multiple), `refill`, `refill_status`,
`cancel`, `balance`). Public documentation page: `/api-docs`. Users create keys
under *API access* (shown once, stored hashed). Rate limit: Admin → Settings → API.

---

## Project structure

```
app/
  Controllers/  Public/ User/ Admin/ Api/ Webhook/   thin HTTP layer
  Core/         App, Router, Request, Response, View, Database, Session, Csrf,
                Validator, Money, Crypto, HttpClient, Mailer, RateLimiter,
                Totp, HtmlSanitizer, Paginator, Logger, ErrorHandler, Env, Config
  Middleware/   auth, admin, perm, csrf, throttle, verified, maintenance …
  Services/     Wallet, Order, ProviderSync, Payment, ManualPayment, Coupon,
                Referral, Ticket, Notification, Mail, Auth, ApiKey, Upload,
                Settings, Seo, Cron, Audit
  Providers/    ProviderAdapterInterface, StandardApiV2Adapter, ProviderFactory
  Payment/      PaymentGatewayInterface, AbstractGateway, GatewayRegistry,
                Gateways/{OxaPay,Cryptomus,P2Gateway}Gateway
  Install/      Installer (web /install), Seeder
  Helpers/      functions.php, Form, Icons
config/app.php          central configuration (reads .env)
cron/                   run.php scheduler + per-task scripts (CLI only)
database/schema.sql     full schema
docs/                   architecture, payment-gateways, provider-api, cron, security
public/                 index.php (only PHP entry point), assets/, uploads/, .htaccess
resources/views/        layouts, partials, public, auth, user, admin, install, errors
routes/                 web.php, admin.php, api.php
storage/                logs/, sessions/, cache/, uploads/ (private), installed.lock
tests/                  integration test suite (php tests/run.php)
.htaccess               protects everything when the project root is public_html
```

> The brief suggested `/Models` and `/Repositories` folders. This codebase keeps
> SQL inside the service classes (one prepared-statement wrapper, no ORM) —
> for an application of this size that is fewer layers to audit, and every
> financial query lives next to the transaction that protects it. Front-end
> assets live in `public/assets` rather than `resources/assets` because there
> is no build step on shared hosting.

---

## Architecture overview

- **MVC-style**: front controller → router → middleware pipeline → controller → service → database; server-rendered PHP views.
- **Money**: exact decimal strings (`App\Core\Money`), `DECIMAL` columns, wallet row locks, immutable ledger with unique references.
- **Orders**: *reserve → submit → settle*. Charge + order creation are one DB transaction; provider submission happens after commit and is claimed atomically; ambiguous provider outcomes are parked for admin review instead of being retried or refunded blindly; partial/cancel refunds are exactly-once.
- **Payments**: gateway adapters behind `PaymentGatewayInterface` (`createPayment`, `handleCallback`, `verifyPayment`, `getPaymentStatus`); single idempotent `complete()` path shared by webhooks, cron, admin verification and manual approval.
- **Providers**: adapters behind `ProviderAdapterInterface`; configurable standard v2 adapter; failures classified as *rejected / unreachable / unknown*.
- **Security**: CSP, CSRF, prepared statements, encrypted secrets, RBAC, rate limits, audit logs — see [`docs/security.md`](docs/security.md).

---

## Feature list

**Public site**: responsive landing page, services & pricing with search/filter,
FAQ (FAQPage schema), About/Terms/Privacy/Refund (editable), contact form
(honeypot + rate limit), API documentation, blog with categories/tags,
clean URLs, meta/OG/canonical tags, breadcrumbs + JSON-LD, sitemap.xml,
robots.txt, light/dark theme.

**Customer panel**: dashboard (balance, order stats, recent orders/transactions,
announcements, quick actions); new order with category→service selection,
search, description, per-type fields (link/username/post URL, quantity,
comments, mentions, poll answer, keywords, drip-feed) and exact live charge;
mass order; orders list with status chips, search and mobile cards; order
detail with progress, timeline, refill/cancel; refills; add funds (gateway or
manual with QR/proof, promo codes, fee display); payment return page;
transactions ledger; tickets with attachments; affiliate link/stats/transfer;
API key management + request log; profile, timezone; security (password, TOTP
2FA, sign out other sessions, login history); notifications.

**Admin panel**: dashboard (users, orders, net revenue, deposits, profit, 14-day
chart, provider & gateway status, cron alerts); users (search/filter/sort,
create, edit, status, price level, custom discount, API switch, credit line,
balance adjustments with reason, security actions, soft delete); balances;
orders (filters, detail with ledger + log, set status, refund, provider sync,
resolve ambiguous submissions); refills; services (filters, bulk
enable/disable/hide/move/markup/price adjust/delete, full editor with type,
link validation, provider mapping, refill/cancel/drip, auto-sync);
categories; providers (connection test, balance, catalog fetch/import,
price sync, API logs, JSON config); payments (filters, gateway verify);
manual payments (proof viewer, approve with amount correction, reject with
reason); transactions (filters, totals); payment gateways (encrypted write-only
credentials, options, limits, fees, manual methods with QR); promo codes;
affiliates (top earners, block/unblock); announcements & notifications
(single user or broadcast, optional email); tickets (priority, status,
assignment, attachments, moderation); pages; FAQ; blog (featured image,
tags, scheduling, SEO fields); SEO; settings (general, contact/social,
currency, users, orders, funds, referral, API, tickets, maintenance mode);
email (SMTP, test, notification toggles, queue status); price levels; admins &
roles; logs (audit, API, webhooks, logins, log files); system health; cron.

---

## Testing

```bash
# needs a disposable MySQL/MariaDB database whose name contains "test"
export TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=smm_test TEST_DB_USER=smm TEST_DB_PASS=secret
php tests/run.php            # all suites
php tests/run.php Payment    # one suite
```

The suite drops and recreates every table in the test database, then runs 178
integration tests through the real services and the full HTTP kernel, with a
fake HTTP transport standing in for providers and gateways:

| Area | Covered |
|---|---|
| Auth | registration + validation, disabled registration, login by username/email, wrong password, user/admin guard separation, HTTP login/logout, TOTP 2FA, password reset (hashed single-use token, expiry, session invalidation, no enumeration), session invalidation, email verification gate |
| Orders | success pricing, server-side price, discounts, invalid/disabled/hidden service, min/max/format/URL validation, insufficient balance, idempotent double submit, provider rejection → refund, provider unreachable → retry, provider timeout → parked (no retry, no refund), invalid response, status sync, exact partial refund once, cancel refund once, refill forward + sync, local cancel & ownership, manual services, admin refund, custom comments, drip-feed, mass order, catalog import & price sync |
| Payments | OxaPay invoice, limits, paid webhook credits once, duplicate callback, invalid/missing HMAC, forged "paid" rejected by server-side check, underpayment held, expired, cron recovery of missed webhook; Cryptomus signed paid (once), invalid signature, fail/cancel; P2Gateway (exact form fields, success/failure/duplicate order_id, timeout/5xx/non-JSON/missing-URL reconciliation, webhook → status-API verification for SUCCESS/COMPLETED/PENDING/FAILED/ERROR, forged webhook, unknown order, replay, amount mismatch → review, admin review approve/reject/re-check (audited, capped, one credit under concurrent approvals, rejected payments never credited later), return page never credits, webhook+cron+return race, token never in HTML/logs); manual approve once / reject / duplicate reference / corrected amount; coupons (cap, per-user, expiry, global limit); referral commission, transfer, self/same-IP blocking |
| Security | CSRF missing/wrong token, stateless exemptions, auth required, user≠admin, cross-user order/ticket access, role permissions (403), session invalidation, stored XSS in user & admin views, HTML sanitizer, SQL injection in searches, upload abuse (PHP-as-JPG, GIF/PHP polyglot re-encoded, oversize), path traversal, login brute force, security headers/CSP, encryption at rest, installer lock, robots.txt |
| API | invalid key, hashed storage, balance, services, add + single/multi status, invalid service/quantity/funds, provider failure, cross-user isolation, per-key rate limit, invalid-key IP throttle, disabled/revoked keys, unknown action |
| Money | exact decimals, ledger before/after, idempotent references, no negative balance, audited admin adjustments, **4 concurrent processes racing on one wallet** |
| Subscriptions | atomic creation with delivery 1, cron processing to completion, claim + per-cycle idempotency (4 concurrent processes → one order), back-off retries → suspend → resume, lifecycle/ownership, HTTP quote/confirm, admin audit |
| Cron | lsphp/php-cgi command-line detection, CLI binary recommendation, task failure recording + log, lock-held skip, overlapping schedulers, interrupted runs, exit codes 0/1/2/3, missing-extension failure, diagnostics, URL trigger |
| Accounts & admin | mobile field off/optional/required, email verification on/off with expiring single-use tokens, resend and grandfathering, balance add/remove ledger + audit + idempotency + permissions, order status transitions and idempotent refunds |
| Currency & levels | exact display conversion, admin validation, per-user choice never converting stored values, base currency in admin/funds, deposit thresholds (credited only, no double count, pending/held/failed/rejected excluded), manual override, safe migration |
| Order page & SEO | platform detection, server-side quote, JSON confirm, double submit, homepage headings/alt/meta/OG/Twitter/JSON-LD, canonical, robots/sitemap consistency, X-Robots-Tag on private pages |

Also verified manually during development: every public, customer and admin
page renders without PHP/JS errors, with no horizontal overflow at 360, 390,
430, 768 and 1366 px; the order form's live price matches the server; the
installer runs end-to-end and locks itself; cron scripts run and skip
overlapping runs; and against a real Apache the `.htaccess` rules return 403 for
`.env`, `.git`, `app/`, `storage/`, `cron/`, `database/`, `composer.json`,
`README.md` and any script in `uploads/` (case-insensitive).

### Manual test checklist before going live
- [ ] Register, verify email (if enabled), log in, enable 2FA, log out, reset password.
- [ ] Add a provider → test connection → import 2–3 services with markup.
- [ ] Place a small real order; watch it move to completed via cron.
- [ ] Make a small **real** OxaPay/Cryptomus payment; confirm webhook log shows *Credited* and the balance increased once.
- [ ] Make a small **real** P2Gateway UPI payment and walk through the live-test table in [`docs/payment-gateways.md`](docs/payment-gateways.md#live-test-required-before-calling-it-production-ready).
- [ ] Submit a manual payment with proof; approve it; submit another and reject it.
- [ ] Create a promo code and use it on a deposit; confirm the bonus transaction.
- [ ] Use the reseller API with a generated key (`balance`, `services`, `add`, `status`).
- [ ] Open a ticket, reply as admin, check the user notification.
- [ ] Check Admin → System health has no red items.

---

## Security checklist

See [`docs/security.md`](docs/security.md) for the full list of controls. Short version:
HTTPS on · `APP_DEBUG=false` · `.env` returns 403 · custom `ADMIN_PATH` · 2FA
for all admins · installer locked · cron running · SMTP working · `APP_KEY`
and database backed up · `TRUSTED_PROXIES` set if you use Cloudflare.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page / 500 | See `storage/logs/app-YYYY-MM-DD.log`. Temporarily set `APP_DEBUG=true` (never leave it on). Check the PHP version is 8.1+. |
| 404 on every page / 403 on `/install` after a Git deploy into `public_html` | The site is running an older root `.htaccess` (before `index.php` existed at the repository root). Redeploy the latest commit, and check that `public_html/.htaccess` and `public_html/index.php` are present. |
| "requires these PHP extensions, which are not enabled" | Enable the named extensions for the **web** PHP version (Hostinger: Advanced → PHP Configuration → PHP extensions). |
| Hostinger Git deploy fails at the Composer step | Make sure `composer.json` and `composer.lock` from this repository are deployed unchanged. They declare no platform requirements. The app does not need Composer at all. |
| 404 on every page except home | `mod_rewrite`/`.htaccess` not active, or `.htaccess` files were not uploaded (hidden files). |
| Redirect loop to https | Behind a proxy/CDN: set `TRUSTED_PROXIES`, or `FORCE_HTTPS=false` and let the host force HTTPS. |
| "Invalid or expired form token" | Session expired, or cookies blocked. Make sure `APP_URL` matches the domain exactly (www vs non-www, https). |
| Styles missing | `APP_URL` wrong (assets are built from it), or document root misconfigured. |
| Orders stay "pending" | Cron not running (dashboard banner), provider disabled, or order is manual-fulfilment. Check Admin → Providers → API logs. |
| Payment flagged "review" | The gateway confirmed a different amount or reference. Open it from Admin → Payments, check the gateway dashboard, then approve, reject or re-check. |
| Orders flagged "needs review" | Provider timed out after receiving the request. Check the provider panel and resolve in the order page. |
| Payment paid but not credited | Admin → Logs → Payment webhooks (signature? amount mismatch?). Admin → Payments → *Verify* re-queries the gateway. Ensure the site is HTTPS and reachable. |
| Emails not sent | Admin → Email → send test; check queue failures; on Hostinger use `smtp.hostinger.com`, port 465 SSL or 587 TLS, the full mailbox address as username. |
| "Unable to decrypt secret" | `APP_KEY` changed. Restore the original key or re-enter provider/gateway credentials. |
| Cron "wrong PHP version" | Use the version-specific PHP binary path shown in Admin → Cron tasks. |
| Uploads fail | `storage/uploads` / `public/uploads` not writable, or `upload_max_filesize` < `UPLOAD_MAX_BYTES`. |

---

## Known limitations

- **No gateway has been tested against live servers.** OxaPay and Cryptomus
  were checked against the vendors' official SDK source code and docs, and
  P2Gateway against its merchant API documentation, all with a fake network
  (the build environment could not reach them). Run one small real payment per
  gateway (or OxaPay sandbox mode) before launch.
- **P2Gateway's webhook payload and signature are not documented.** Callbacks
  are treated as unsigned notifications and every payment is confirmed through
  `check-order-status`. No sandbox or test mode is documented, and the gateway
  is only offered when the site currency is INR (the account currency setting).
- **Provider integration** follows the standard API v2 contract; each provider's
  real responses should be checked once with *Test connection*/*Fetch services*.
  Exotic providers may need the JSON parameter map or a custom adapter.
- 2FA setup shows a secret key and an `otpauth://` link, not a QR image (no
  external QR library/CDN is used).
- Page/blog editing is a raw HTML textarea (sanitised), not a WYSIWYG editor.
- One base (accounting) currency per site; changing it later does not convert
  existing balances. Other currencies are display-only, with admin-set rates.
- Auto-subscriptions are panel-side (repeated normal orders). Provider-native
  "Subscriptions" services (billed per new post) are not supported: the standard
  API v2 documents no billing or status format for them.
- Charts are simple server-rendered SVG (no JS charting library).
- Deposits are refunded to the balance, never back to the original payment method (no payout integrations).
- The demo catalog seeder (`tests/dev-seed.php`) is for local previews only.
