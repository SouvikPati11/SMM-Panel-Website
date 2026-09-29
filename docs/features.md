# Subscriptions, currencies, price levels, registration and admin controls

## Auto-subscriptions

A subscription repeats the same order on a schedule: every hour, 3, 6 or 12
hours, daily, every 2 or 3 days, or weekly, for 2 up to *Maximum deliveries*
(Settings → Orders, default 100).

- **Enable** per service: Admin → Services → edit → *Allow auto-subscriptions*.
  Settings → Orders → *Auto-subscriptions enabled* is a global switch.
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

- **Mobile number:** off, optional or required. Numbers are normalised
  (`+` and country code, 7–15 digits). While the field is off, nothing is
  collected or required.
- **Email verification:** new accounts must confirm their email before using
  the panel. Links are 64-hex random tokens stored as SHA-256 hashes, are
  single-use and expire after 48 hours. Users can resend them (rate limited).
  Accounts created before you switch verification on keep working, unless they
  later change their email. Switching it off unblocks everyone.

## Admin balance add / remove

Admin → Users → user → *Add / remove balance* (permission `users.balance`):

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
