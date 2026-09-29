# Architecture & Research Notes

This document records the research that preceded implementation (Phase 1) and the
resulting architecture (Phase 2). It is the reference for *why* the code is shaped the
way it is.

---

## 1. Research summary (September 2026)

### 1.1 What a modern SMM panel contains

Surveying current reseller panels and panel-software vendors shows a very stable
feature set. The differentiators in 2026 are reliability (no double charges, correct
partial refunds), speed of the order form on mobile, and a trustworthy wallet.

| Area | Industry convention | Decision here |
|---|---|---|
| Pricing | Rate is quoted **per 1000 units** | `services.rate` = price per 1000, charge = `rate × qty / 1000` |
| Catalog | Category → service, numeric public service ID, min/max, "average time", description | Same, plus per-service `link_label` and typed fields |
| Service types | Default, Package, Custom Comments, Mentions, Comment Likes, Poll, Drip-feed, Subscriptions | Default, Package, Custom Comments, Custom Comments Package, Mentions (custom list), Comment Likes, Poll, Keywords; drip-feed as a flag |
| Order form | Category → service → description → link → quantity → live charge | Same, with search, fully server-side re-priced |
| Mass order | One line per order: `service_id|link|quantity` | Same, validated per line, each line is an independent atomic order |
| Statuses | Pending, In progress, Processing, Completed, Partial, Canceled | Pending, Processing, In progress, Completed, Partial, Cancelled, Refunded, Failed |
| Refill / Cancel | Per-service flags, request buttons on order list | Same, forwarded to provider, refill status tracked |
| Provider API | De-facto "API v2": single URL, form-encoded POST, `key` + `action` (`services`, `add`, `status`, `refill`, `refill_status`, `cancel`, `balance`), JSON responses | Generic adapter implementing exactly this, with a per-provider **parameter/action map** for panels that deviate |
| Reseller API | Panels expose the *same* v2 format so resellers can chain panels | `/api/v2` implementing the same contract |
| Wallet | Prepaid balance, every change is a ledger line | `wallets` + immutable `transactions` ledger with before/after balance |
| Payments | Crypto processors + local methods + manual (UPI/bank) with proof | OxaPay, Cryptomus, P2Gateway (UPI), Manual |
| Support | Ticket categories (Order, Payment, API, Other), order ID field | Same, with attachments, priority, assignment |
| Affiliates | % of referred users' deposits, payout threshold | Same, commission credited in the same DB transaction as the deposit |
| Growth | Coupons that add bonus on deposit, announcements, blog for SEO | Same |

### 1.2 Payment gateways — verified sources

External documentation sites were not directly reachable from the build environment,
so each integration was verified against the vendor's **official SDK source code**
(fetched from the vendor's GitHub organisation / Packagist) plus search-indexed
excerpts of the official docs.

**OxaPay** (official `oxapay/oxapay-php` SDK, API v1)
- Base URL `https://api.oxapay.com/v1`
- Auth: HTTP header `merchant_api_key: <key>`
- Create invoice: `POST /payment/invoice` (JSON). Params: `amount`, `currency`,
  `lifetime` (minutes), `fee_paid_by_payer`, `under_paid_coverage`, `to_currency`,
  `auto_withdrawal`, `mixed_payment`, `callback_url`, `return_url`, `email`,
  `order_id`, `thanks_message`, `description`, `sandbox`.
  Response envelope `{data:{track_id,payment_url,expired_at,date}, message, error, status, version}`.
- Payment info: `GET /payment/{track_id}` → `data.status` etc.
- Webhook: JSON POST; header `HMAC` = `hash_hmac('sha512', raw_body, MERCHANT_API_KEY)`.
  Payload includes `track_id`, `status` (`Paying`, `Paid`, …), `type` (`invoice`),
  `amount`, `currency`, `order_id`, `txs[]`. Must respond `ok` with HTTP 200.

**Cryptomus** (official `cryptomus/api-php-sdk`)
- Base URL `https://api.cryptomus.com/`
- Auth headers: `merchant: <merchant uuid>`, `sign: md5(base64_encode(json_body) . PAYMENT_API_KEY)`
- Create invoice: `POST v1/payment` — `amount`, `currency`, `order_id`, `url_return`,
  `url_callback`, `lifetime` (seconds), `is_payment_multiple`, `to_currency`, `network`.
  Response `{state:0, result:{uuid, url, status, ...}}`.
- Info: `POST v1/payment/info` with `uuid` or `order_id`.
- Webhook: JSON body containing `sign`. Verify by removing `sign`, then
  `md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . API_KEY)`.
  Statuses: `paid`, `paid_over` (success, final), `wrong_amount`, `fail`, `cancel`,
  `system_fail`, `refund_*`, plus intermediate (`process`, `check`, `confirm_check`).
  `is_final` marks terminal states.

**P2Gateway.in** — implemented from the merchant API documentation supplied by the
site owner. It uses `POST /api/create-order` and `POST /api/check-order-status`
(form-encoded, `user_token`). The webhook payload and signature are undocumented,
so callbacks only trigger a status-API check. Details are in `docs/payment-gateways.md`.

**Manual** — fully internal: admin-defined methods (UPI/bank/crypto address) with QR
image, min/max; users submit reference + optional proof; admin approves/rejects.

### 1.3 Security research highlights
- Never credit from a redirect: only signed webhooks **or** a server-side status query
  may credit a wallet, and crediting is idempotent (row lock + unique ledger reference).
- Provider submission cannot live inside a DB transaction (network I/O while holding
  locks), so the order engine uses a **reserve → submit → settle** pattern with an
  explicit "submission unknown" state rather than blind retries.
- Shared hosting: file-based sessions, MySQL named locks (`GET_LOCK`) for cron
  exclusivity, DB-backed rate limiting (no Redis).

---

## 2. Architecture

### 2.1 Runtime constraints
PHP 8.1+, MySQL 5.7+/MariaDB 10.3+, Apache with `mod_rewrite`. No Node, no daemons,
no Redis. Composer is **optional** — the app ships its own PSR-4 autoloader and has no
required third-party runtime packages. Required extensions: `pdo_mysql`, `mbstring`,
`json`, `curl`, `openssl`, `fileinfo`, `sodium`; recommended: `bcmath`, `gd`, `intl`.

### 2.2 Layers

```
public/index.php  ── front controller (only web-reachable PHP entry point)
  └─ app/Core/App          bootstrap: env, error handling, DB, session, router
      ├─ routes/web.php, routes/admin.php, routes/api.php
      ├─ app/Middleware     auth, admin auth + permission, CSRF, guest, maintenance,
      │                     installed, rate limit, API key
      ├─ app/Controllers    thin: validate input → call service → render view
      │    ├─ Public/  User/  Admin/  Api/  Webhook/
      ├─ app/Services       business logic, owns DB transactions
      │    ├─ WalletService      the ONLY code that changes balances
      │    ├─ OrderService       reserve → submit → settle, refunds, refills, cancels
      │    ├─ ProviderSyncService status sync, service import/sync
      │    ├─ PaymentService     create payment, idempotent credit, expire
      │    ├─ ReferralService, CouponService, TicketService, NotificationService,
      │    │  AuthService, TwoFactorService, SettingsService, AuditService,
      │    │  UploadService, MailService, SeoService, CronService
      ├─ app/Repositories  SQL access (prepared statements only)
      ├─ app/Providers     SMM provider adapters (ProviderAdapterInterface)
      └─ app/Payment       payment gateway adapters (PaymentGatewayInterface)
cron/*.php                 CLI entry points (refuse to run over HTTP)
install/                   web installer (locked after install)
```

### 2.3 Money
Money columns are `DECIMAL(18,6)` for balances, rates and charges and
`DECIMAL(18,4)` for payment amounts. PHP never uses floats for money: `App\Core\Money` performs
decimal-string arithmetic (bcmath when available, a pure-PHP exact fallback
otherwise). Balance changes are applied by SQL on a row locked with
`SELECT … FOR UPDATE`, and every change writes a ledger row in `transactions`
with `balance_before` / `balance_after`.

### 2.4 Order engine (reserve → submit → settle)

```
TX1  lock wallet FOR UPDATE ─ check balance ─ debit ─ insert order(status=pending,
     submit_state=queued) ─ ledger(order_charge, ref "order:{id}:charge") ─ COMMIT
     (unique (user_id, idempotency_key) blocks double-submits of the same form)

HTTP provider.addOrder()
  ├─ success  → order.provider_order_id, status=processing, submit_state=submitted
  ├─ provider rejected (JSON error) → TX2 refund (ref "order:{id}:refund"), status=failed
  ├─ connection never established (DNS/connect error) → submit_state=queued;
  │     cron retries (request provably never reached provider)
  └─ timeout / garbage response after send → submit_state=unknown, flagged for admin.
        NEVER auto-retried or auto-refunded (could duplicate a real provider order).
```
Refund references are unique per order, so a partial/cancel refund can only happen
once no matter how many times sync runs. Manual-fulfilment services (no provider)
stay `pending` for admin processing.

### 2.5 Payment crediting
```
webhook ─ log raw (webhook_logs) ─ verify signature ─ find payment by gateway ref
        ─ (OxaPay/Cryptomus) re-query gateway status server-side
        ─ PaymentService::complete(): TX: lock payment FOR UPDATE; if already
          completed → no-op; else credit wallet (ref "payment:{id}"), apply coupon
          bonus (ref "payment:{id}:bonus"), referral commission (unique payment_id)
```
The amount credited is the amount **stored at creation**, never the callback or
browser amount; callbacks for a different amount/currency are rejected and logged.

### 2.6 Database
See `database/schema.sql` (authoritative). Tables:

`users, wallets, transactions, price_levels, admins, roles, permissions,
role_permissions, admin_roles, password_resets, email_verifications, login_attempts,
categories, services, providers, provider_services, orders, order_logs,
refills, payments, payment_methods, manual_payment_requests, coupons, coupon_usage,
referrals, referral_transactions, tickets, ticket_messages, ticket_attachments,
notifications, announcements, pages, faqs, blog_categories, blog_posts, blog_tags,
blog_post_tags, settings, api_keys, api_logs, provider_logs, webhook_logs,
audit_logs, rate_limits, email_queue, cron_runs`

All timestamps are stored in UTC (`SET time_zone='+00:00'` per connection) and
displayed in the configured site timezone.

### 2.7 Secrets
`.env` (outside web access, blocked by `.htaccess`) holds DB credentials and
`APP_KEY`. Gateway and provider credentials entered in the admin panel are encrypted
at rest with libsodium `secretbox` keyed from `APP_KEY`; they are never rendered back
to the browser (write-only inputs) and never sent to JavaScript.

### 2.8 Front end
Server-rendered PHP views, one hand-written CSS design system (`public/assets/css/app.css`,
light + dark via `prefers-color-scheme` and a manual toggle), one small vanilla JS file.
No inline scripts (strict CSP), system font stack (no external requests), tables
collapse into labelled cards under 768px, mobile bottom navigation in the user panel.
