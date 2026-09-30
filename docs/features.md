# Subscriptions, currencies, price levels, registration and admin controls

## Auto-subscriptions

A subscription repeats the same order on a schedule: every hour, 3, 6 or 12
hours, daily, every 2 or 3 days, or weekly, for 2 up to *Maximum deliveries*
(Settings → Orders, default 100).

- **Service type "Subscriptions"** (Admin → Services → *Service type*): the
  service is sold **only** as a subscription. Name, category, provider,
  provider service ID, price per 1000, min/max quantity, description and status
  work as for any service. The **Subscription settings** card adds the allowed
  intervals, and the minimum and maximum number of deliveries (capped by
  Settings → Orders). The admin services list marks these services with a
  *Subscription* badge and can filter by type. They are not offered through the
  reseller API v2, and one-time, mass and API orders for them are refused.
- **Any other service** can also allow subscriptions (*Also allow
  auto-subscriptions*), so users choose one-time or repeated delivery.
- Settings → Orders → *Auto-subscriptions enabled* is a global switch.
- **Ordering:** on New order, services that allow it show *Order type:
  One-time / Auto-subscription*. The confirmation dialog shows the per-delivery
  charge, the number of deliveries and the estimated total.
- **Charging:** delivery 1 is ordered and charged immediately, in the same
  transaction that creates the subscription. If it can't be paid, nothing is
  created. Each later delivery is a **normal order** placed by the
  `subscriptions` cron task (every minute) through the regular order engine, so
  pricing, discounts, the ledger, provider submission, status sync and refunds
  are all unchanged. The price is the price at the time of each delivery.
- **Lifecycle:** `active` → `paused` ⇄ `active` → `completed` or `cancelled`.
  After repeated failures a subscription becomes `suspended`; resume it to
  continue. Users manage theirs under **Subscriptions**. Admins use Admin →
  Subscriptions (inspect, pause, resume, cancel with a reason, or run the next
  delivery now). Every change is logged in the subscription history and the
  audit log.
- **No duplicate processing:**
  - a due subscription is *claimed* with an atomic `UPDATE … locked_until`, so
    two cron runs never process it at once;
  - each delivery's order carries the idempotency key `sub{id}c{n}`, unique per
    user, so even an expired claim can't order a delivery twice;
  - the cycle counter only advances from the expected previous value.
- **Retry policy:** a failed delivery (e.g. insufficient balance, service
  disabled) is retried after 15 min, 1 h, 3 h, 6 h and 12 h, then the
  subscription is suspended. The user is notified on the first failure and on
  suspension, and the admin is notified on suspension. Unexpected errors are
  also written to `storage/logs/subscription-*.log`.
- **Orders list:** subscription orders are marked *Subscription #id · delivery
  n*, and both the user and admin order lists can be filtered by type.

**Limitation:** provider-native "Subscriptions" services, the kind with
username/min/max/posts/delay/expiry parameters that are billed per new post,
are not used. The standard API v2 contract this panel implements documents
neither per-post billing nor a subscription status format, so charging for them
safely is not possible. Panel-side subscriptions work with every existing
service and provider.

## Display currencies

- The **base currency** is Settings → Currency. Every balance, price, ledger
  entry, payment and provider cost is stored and charged in it.
- Admin → **Currencies** adds display currencies with a manual exchange rate
  (units per 1 base unit), a symbol, a position and decimals. Nothing fetches
  rates externally.
- Users pick a currency in their profile or the account menu. It is stored in
  `users.currency`. Prices are converted with exact decimal arithmetic
  (half-up) for display only. **Changing currency never converts stored
  balances or ledger values.**
- Always shown in the base currency: deposit amounts and limits, payment
  history, admin pages, the API, emails and cron output. When a user views
  another currency, the Add funds page says so.

## Price levels by deposits

- Admin → **Price levels** holds a name, a description, the discount %, and the
  **minimum lifetime deposits** needed to reach the level. Leave the minimum
  empty for a manual-only level.
- **Qualifying amount = lifetime credited deposits** (`wallets.total_deposits`),
  not the current balance. It changes only when a deposit is actually credited
  to the ledger: a gateway payment completed, a manual payment approved, a held
  payment approved, or an admin balance change recorded as *Deposit*. Pending,
  held-for-review, failed, expired and rejected payments never count. The ledger
  reference is unique per payment, so replays never double-count.
- A user's level updates automatically inside the deposit transaction and after
  levels are edited. Admins can pin a level (manual). Choosing *Automatic (by
  deposits)* switches back.
- The admin user page shows the current level, qualifying deposits, the next
  level and the amount remaining. Users see their level and progress in Profile.
- **Upgrade safety:** on existing installations, levels an admin had assigned
  stay assigned (as manual), and existing levels get no threshold, so nobody is
  auto-promoted by the upgrade. Set thresholds when you are ready. Fresh
  installs start with Standard ($0), Reseller ($100) and VIP ($500).

## Registration settings (Settings → Users)

- **Mobile number ON/OFF:**
  - **ON:** the registration form (and the Google *One last step* form) shows
    the field, and it is **required and validated** on the server (`+` and
    country code, 7–15 digits, normalised to e.g. `+447700900123`).
  - **ON + *Let users leave the mobile number empty*:** the field is optional,
    and a number that is given is still validated.
  - **OFF:** the field is not rendered, nothing is required, and a posted value
    is ignored and not stored.
  - Existing accounts without a number keep working in every mode.
  - Upgrade note: before 2026_10_12, "ON" alone meant optional. Installations
    that used it that way are migrated with *optional* ticked, so their
    behaviour doesn't change.
- **Email verification:** new accounts must confirm their email before using
  the panel. Links are 64-hex random tokens stored as SHA-256 hashes, are
  single-use and expire after 48 hours. Users can resend them (rate limited).
  Accounts created before you switch verification on keep working, unless they
  later change their email. Switching it off unblocks everyone.

## Admin balance add / remove

Admin → Users → user → **Add balance** / **Remove balance** (buttons in the page
header and the *Balance* card; `?balance=add|remove` links from the Users and
User balances lists open the dialog directly). Requires the permission
`users.balance`:

- a reason is required;
- every change creates a ledger entry with the balance before and after, the
  amount, the admin and the time, plus an audit-log entry;
- the balance cannot go below zero unless the user's *credit line* allows it;
- the form carries a one-time key, so a double submit applies once;
- the **Balance adjustments** tab lists them all.

## Admin order status changes

Only lifecycle-valid transitions are offered: `pending` → processing,
in_progress, completed, partial or cancelled; `processing` → in_progress,
completed, partial or cancelled; `in_progress` → completed, partial or
cancelled.

Final orders are locked, and a completed order can only be refunded explicitly.
A reason is required. The order log and audit log record the old status, the
new status, the admin and the refund amount.

**Money:** *partial* refunds the undelivered part, *cancelled* refunds whatever
has not yet been refunded, and every other transition moves no money. The
effect is shown in the status selector before you confirm. Refunds use unique
ledger references, so they never run twice.

## New order page

- Platform shortcuts (only platforms that have services) filter the categories.
  Categories and search results show platform icons.
- A loading state appears while a description or the price is fetched. Price
  requests are debounced and superseded, and stale answers are ignored.
- **Review order** fetches a server-side quote and opens a confirmation dialog
  showing the service, link, quantity, rate, total (or subscription schedule)
  and service notes. **Confirm** places the order, and a success state shows
  the order number.
- Double submission is blocked in the browser (busy state) and on the server
  (idempotency key per form).

## Remember me

The login form has *Keep me signed in for 30 days*. The browser gets an
HttpOnly, SameSite=Lax cookie `<SESSION_NAME>_rm` holding `selector:validator`.
Only SHA-256(validator) is stored (`remember_tokens`).

- The token is rotated on every use, and a reused (stolen) token is rejected
  and deleted.
- Tokens are invalidated by a password change or reset, by "Sign out other
  sessions", by an admin resetting sessions (session version), and by logout
  (this browser only).
- They expire after 30 days, and the daily cleanup task deletes expired ones.
- The API and webhooks never read the cookie.

## Sign in with Google

See [`google-login.md`](google-login.md) for setup (Google Cloud Console,
Admin → Settings → Users or `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` /
`GOOGLE_REDIRECT_URI` in `.env`) and the account-matching rules.

## Login and registration pages

- A card layout with a *Continue with Google* button (only shown when Google is
  enabled and configured).
- Inputs have icons and passwords have a show/hide toggle.
- Registration shows live password rules.
- Errors appear inline and don't disappear.
- Submit buttons show a loading label and can't be submitted twice.
- Remember me, forgot password, and login/register links.

## Admin blog

Admin → Blog:

- Tabs for All / Published / Scheduled / Drafts with counts, plus search.
- Each row shows the featured image, category, date and views, with Edit, View,
  Publish/Unpublish and Delete actions.
- The editor validates the publish date (a future date schedules the post),
  keeps the old image until the new one is saved, and explains the SEO fields.
- Only live posts appear on `/blog`, in category counts and in the sitemap.

## API Access page (user panel)

Shows:

- the endpoint (copy button) and key status (Active / No key yet / Disabled);
- the masked key with created/last-used info;
- the method, format and rate limit;
- quick-start steps with copyable examples (balance, add order, PHP status) and
  sample responses;
- all supported actions, a security warning, and recent requests.

The full key is shown once, right after it is generated. Only its hash is
stored.
