# Security

Security controls built into the application, and the checklist to follow when
deploying. Every control listed under "Implemented" is covered by
`tests/SecurityTest.php`, `tests/AuthTest.php`, `tests/ApiTest.php`,
`tests/PaymentTest.php` or `tests/WalletTest.php` unless noted.

## Implemented

### Input, output, database
- **SQL injection**: every query uses PDO prepared statements with bound values, `ATTR_EMULATE_PREPARES=false`; identifiers in the `insert/update` helpers are validated against `^[A-Za-z_][A-Za-z0-9_]*$`; `LIKE` wildcards in searches are escaped. MySQL strict mode is enabled per connection.
- **XSS**: all output goes through `e()` (`htmlspecialchars`, ENT_QUOTES, UTF-8). User-supplied links are rendered with `link_html()`, which makes only `http(s)` URLs clickable. Admin-authored HTML (pages, blog, announcements) passes an allow-list DOM sanitizer on save *and* on render. Strict **Content-Security-Policy** (`script-src 'self'`, no inline scripts), plus `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS.
- **Validation**: declarative `Validator`, type-safe request accessors (arrays are rejected where scalars are expected), server-side re-computation of every price.

### Authentication & sessions
- Passwords hashed with `password_hash()` (bcrypt/argon per PHP default), rehashed automatically when the algorithm changes; strength rules (8+ chars, letter + number, common-password block; 10+ for admins).
- Separate guards and tables for **users** and **admins**; different session keys; being logged in as one never grants the other.
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, strict mode, custom name, ID regenerated on login/logout and every 15 minutes, idle (2 h) and absolute (7 d) timeouts.
- `session_version` per account: password change, password reset, suspension or "sign out other sessions" invalidates all other sessions immediately.
- Brute-force protection: failures counted per identifier **and** per IP (configurable attempts/lockout), constant-time password verification even for unknown users, generic error messages; route-level throttles on login, 2FA, registration, password reset, contact, orders, tickets, funds.
- TOTP two-factor authentication (RFC 6238) for users and admins; 2FA secrets encrypted at rest.
- Password reset: 256-bit token, only its SHA-256 hash stored, 60-minute expiry, single use, no account enumeration, rate limited.
- Optional email verification gate.

### Authorization
- Middleware on every route group (`auth`, `verified`, `admin`, `perm:*`).
- Admin **roles & permissions** (22 permissions, 4 default roles, super-admin flag); checked on routes *and* used to hide navigation.
- Every user-owned query is scoped by `user_id` (orders, tickets, attachments, payments, API).
- Protections against locking yourself out (can't disable/delete yourself or the last super admin).

### CSRF
- Per-session token on every form and AJAX POST (`hash_equals` comparison); invalid → HTTP 419.
- Stateless endpoints (`/api/v2`, `/webhooks/*`, `/tasks/run/*`) have no session and authenticate by API key, signature or secret instead.

### Money
- Balances change only through `WalletService::apply()`: row lock (`SELECT … FOR UPDATE`), ledger row with before/after balance, unique idempotency reference, negative-balance guard. Verified with a multi-process race test.
- Decimal arithmetic without floats (`App\Core\Money`, bcmath or exact pure-PHP fallback); `DECIMAL` columns.
- Orders: idempotency key per form, atomic submission claim, no blind retries of non-idempotent provider calls, refunds unique per order and kind.
- Payments: signature verification, server-to-server status confirmation, amount/currency check, idempotent completion, unique `(gateway, gateway_ref)`, browser redirects never credit, gateway payment URL must be `https://`.
- Manual payments: unique reference per method, one-time approval under row lock, admin-only proof viewing.
- Coupons & referrals: usage limits enforced under row lock at credit time; unique per payment; self-referral and same-IP referrals blocked.

### Files
- Uploads: size limit, MIME from content (`finfo`) with allow-list, `getimagesize` + GD re-encoding (strips metadata and polyglot payloads), random 128-bit file names, private files outside the web root served only after an ownership check, `nosniff` + sandbox CSP on served files.
- `public/uploads/.htaccess` disables script execution (case-insensitive) — verified against Apache.

### Secrets & configuration
- `.env` outside `/public`, blocked by `.htaccess` (dotfiles, `app/`, `config/`, `storage/`, `cron/`, `database/`… return 403 even when the whole project sits in `public_html`) — verified against Apache.
- Provider API keys, gateway credentials, SMTP password and 2FA secrets are encrypted with libsodium `secretbox` using a key derived from `APP_KEY`; credential inputs are write-only (only masked values are ever rendered); keys are redacted from all logs.
- Reseller API keys are stored as SHA-256 hashes and shown once.
- Production mode never displays PHP errors; everything is logged to `storage/logs`. Deprecation notices are logged, never fatal.

### API & abuse
- Reseller API: per-key rate limit (configurable), per-IP throttle on invalid keys, per-user API switch, every call logged (key redacted).
- Webhook endpoint: rate limited, 256 KB body limit, every delivery logged with signature result.
- DB-backed rate limiter (no Redis needed).
- Registration limit per IP per day, open-invoice cap per user, open-ticket cap per user, contact form honeypot.

### Audit trail
`audit_logs` records admin logins, balance adjustments (admin ID, amount, reason), user/service/provider/gateway/settings changes (with before/after where relevant), order status changes and refunds, manual payment decisions, role changes, cron runs. Financial events are also in the immutable `transactions` ledger and `order_logs`.

## Deployment security checklist

- [ ] HTTPS certificate installed; `APP_URL` starts with `https://`; `FORCE_HTTPS=true`.
- [ ] Domain document root points to `/public` **or** the root `.htaccess` is present (verify: `https://example.com/.env` → 403).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] `APP_KEY` generated by the installer and **backed up** (without it, encrypted credentials cannot be decrypted).
- [ ] Custom `ADMIN_PATH` (not `admin`).
- [ ] 2FA enabled for every administrator (System health warns otherwise).
- [ ] `/install` returns 404 (lock file `storage/installed.lock` present).
- [ ] `storage/` and `public/uploads/` writable by PHP; nothing else writable. Suggested: files 644, directories 755, `.env` 640.
- [ ] Cron running (dashboard shows no "cron" banner).
- [ ] SMTP configured and test email received (password resets depend on it).
- [ ] Payment gateway webhooks point to your HTTPS domain; test one small real payment.
- [ ] If behind Cloudflare/another proxy, set `TRUSTED_PROXIES` so rate limits and logs see real client IPs.
- [ ] Database user has privileges only on this database.
- [ ] Regular backups of the database **and** `.env`.
- [ ] Review Admin → System health after every update.

## Reporting issues

Check `storage/logs/app-*.log`, `payment-*.log`, `provider-*.log`,
`webhook-*.log` and the Admin → Logs screens. Never paste `.env` contents or
API keys into support tickets.
