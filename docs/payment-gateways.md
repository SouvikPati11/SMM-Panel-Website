# Payment gateways

Admin → **Payment gateways** lists every method. Automatic gateways (OxaPay,
Cryptomus, P2Gateway) share one interface; manual methods (UPI, bank, crypto
address…) are unlimited and fully configurable.

## How crediting works (applies to every gateway)

```
User clicks "Continue to payment"
  → server validates amount limits + promo code, creates `payments` row (pending)
  → server stores a unique merchant order id (payments.merchant_order_id)
  → server creates the invoice at the gateway, stores its reference (gateway_ref), redirects the browser
Gateway calls /webhooks/{gateway}
  → raw request stored in webhook_logs (tokens/keys masked)
  → signed gateways (OxaPay, Cryptomus): signature verified (reject → HTTP 401)
    unsigned gateways (P2Gateway): payload content ignored except the order reference
  → payment located by gateway_ref / merchant_order_id
  → server queries the gateway's status API; PaymentService::settle():
      reference matches? → status completed? → amount OK? → complete()
  → PaymentService::complete(): row lock + idempotent ledger entry "payment:{id}"
Cron `payments` every 5 min re-checks pending invoices (missed webhooks) and expires old ones.
The return page and Admin → Payments → Verify use the same server-side check.
```

Guarantees:
- The browser return URL (`/funds/return/{id}`) **never** credits from browser data. It may trigger the same server-to-server status query (throttled to once per 15 s per payment); only the gateway's answer counts.
- Every payment stores: requested amount (+fee), local payment id, merchant order id, gateway order id, status, verified amount and UTR/transaction reference (where the gateway provides one).
- The amount credited is the amount stored when the invoice was created — never a value from the callback or the browser.
- Duplicate or replayed callbacks are no-ops (`status = completed` check under `SELECT … FOR UPDATE` + unique ledger reference).
- Amount/currency mismatches are held (not credited) and flagged in Admin → Payments with an email alert.
- Credentials are encrypted in the database with `APP_KEY` (libsodium) and are write-only in the admin UI.

### Payments held for review

A payment is held (`needs_review = 1`) when the gateway confirms a different
amount, currency or order reference. Nothing is credited, cron skips it, and the
admin gets one email. Admin → Payments shows a banner and a **Review** button
that opens the payment page (`/admin/payments/{id}`). That page shows the
requested and confirmed amounts, the UTR, the references, callbacks, ledger
entries and the decision history. There are three decisions, each needing the
`payments.manage` permission and a note:

| Action | Effect |
|---|---|
| **Approve & credit** | Credits the amount you enter through the normal crediting path (`PaymentService::complete`): one ledger entry `payment:{id}`, plus any promo bonus and referral commission based on that amount. The field is prefilled with the confirmed amount minus the fee. It is capped at the larger of the requested and the confirmed amount; for anything more, use a balance adjustment. |
| **Reject** | No credit. A pending payment becomes `failed`, and the user is notified with your reason. Later callbacks, cron and the return page can never credit it. |
| **Re-check & release** | Clears the hold and queries the gateway immediately. Credited only if the gateway now confirms the exact amount; otherwise it is held again. |

Each decision locks the payment row and only works while it is still held,
so two admins acting at once produce one outcome. Held payments that already
expired or failed can also be approved or rejected. Every decision is added to
the payment's review history and written to the audit log (`payment.review.*`)
with the amounts, the original hold reason and your note.

Webhook URLs (also sent automatically with every invoice):

| Gateway | Callback URL |
|---|---|
| OxaPay | `https://YOUR-DOMAIN/webhooks/oxapay` |
| Cryptomus | `https://YOUR-DOMAIN/webhooks/cryptomus` |

HTTPS is required — gateways will not deliver webhooks to plain HTTP in production.

---

## OxaPay

Implementation: `app/Payment/Gateways/OxaPayGateway.php`

**Verified against** OxaPay's official PHP SDK (`oxapay/oxapay-php`, API v1) and
the official docs (https://docs.oxapay.com/api-reference/payment/generate-invoice,
https://docs.oxapay.com/webhook):

| Item | Value |
|---|---|
| Base URL | `https://api.oxapay.com/v1` |
| Auth | header `merchant_api_key: <MERCHANT_API_KEY>` |
| Create invoice | `POST /payment/invoice` (JSON) → `data.track_id`, `data.payment_url`, `data.expired_at` |
| Status query | `GET /payment/{track_id}` → `data.status` |
| Webhook signature | header `HMAC` = `hash_hmac('sha512', raw_body, MERCHANT_API_KEY)` |
| Success status | `Paid` (anything else is pending or terminal-failed) |

Setup:
1. OxaPay dashboard → Merchant Service → create a merchant API key.
2. Admin → Payment gateways → **Crypto (OxaPay)** → paste the key.
3. Options: invoice lifetime (minutes), whether the payer pays the fee, underpaid coverage %, optional auto-convert currency, sandbox mode (testing only), optional webhook IP allow-list.
4. Set min/max and status **Active** → Save.

Sent parameters: `amount, currency, lifetime, fee_paid_by_payer, under_paid_coverage, callback_url, return_url, order_id (PAY-{id}), description, sandbox, email, to_currency (optional)`.

Testing: enable **Sandbox mode**, deposit a small amount, and watch Admin → Logs → Payment webhooks. Disable sandbox before going live.

---

## Cryptomus

Implementation: `app/Payment/Gateways/CryptomusGateway.php`

**Verified against** Cryptomus' official PHP SDK (`cryptomus/api-php-sdk`) and
the official docs (https://doc.cryptomus.com):

| Item | Value |
|---|---|
| Base URL | `https://api.cryptomus.com/` |
| Auth headers | `merchant: <merchant uuid>`, `sign: md5(base64_encode(json_body) . PAYMENT_API_KEY)` |
| Create invoice | `POST v1/payment` → `result.uuid`, `result.url`, `result.expired_at` |
| Status query | `POST v1/payment/info` `{"uuid": …}` |
| Webhook signature | body field `sign`; remove it, then `md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . API_KEY)` |
| Success status | `paid`, `paid_over` |
| Not credited | `wrong_amount` (underpaid, final), `fail`, `system_fail`, `cancel`, `refund_*` |

The official Cryptomus SDK is **not** used as a dependency (it has no external
requirements, but bundling our own ~150-line adapter keeps the app installable
without Composer on shared hosting). The request signing is byte-identical to the SDK.

Setup:
1. Cryptomus → Business → your merchant → Settings: copy **Merchant UUID** and the **Payment API key** (not the payout key).
2. Admin → Payment gateways → **Crypto (Cryptomus)** → paste both.
3. Options: invoice lifetime (seconds, 300–43200), optional `to_currency`, optional webhook IP allow-list (Cryptomus publishes its webhook IP in its docs).
4. Activate and save.

`wrong_amount` payments are not credited automatically: check the paid amount in the Cryptomus dashboard and credit the user manually (Admin → Users → Adjust balance → record as *Deposit*), which keeps the audit trail intact.

---

## P2Gateway.in (UPI)

Implementation: `app/Payment/Gateways/P2GatewayGateway.php` ·
Tests: `tests/P2GatewayTest.php` (24 tests)

**Source:** the P2Gateway Merchant API documentation from the merchant dashboard,
supplied by the site owner. Only what is documented there is used:

| Item | Documented value |
|---|---|
| Auth | API token from the Merchant Dashboard, sent as form field `user_token` |
| Create order | `POST https://p2gateway.in/api/create-order`, `application/x-www-form-urlencoded`: `customer_mobile`, `user_token`, `amount`, `order_id`, `redirect_url`, `remark1` |
| Create response (ok) | `{"status":true,"message":"Order Created Successfully","result":{"orderId":"…","payment_url":"…"}}` |
| Create response (error) | `{"status":false,"message":"order_id already exists"}` |
| Order timeout | 30 minutes, then the order is failed automatically |
| Check status | `POST https://p2gateway.in/api/check-order-status` (form): `user_token`, `order_id` |
| Status response | `{"txnStatus":"COMPLETED","resultInfo":"Transaction Success","orderId":"…","status":"SUCCESS","amount":"…","date":"…","utr":"…"}` |
| Webhook | Configurable **Webhook URL** in the Merchant Dashboard; payload format and signature are **not documented** |

### How it is used

- **Order IDs**: each payment gets a new, never-reused merchant `order_id`
  (`SMM{paymentId}T{8 random hex}`, unique index). Stored chain:
  `payments.id` → `payments.merchant_order_id` (sent as `order_id`) →
  `payments.gateway_ref` (P2Gateway `result.orderId`).
- **customer_mobile**: asked on the Add Funds form (10-digit Indian mobile;
  `+91`/`0` prefixes normalised), stored in the payment's meta, and prefilled
  next time.
- **amount**: the server-side amount + fee, never a browser value.
  `redirect_url` = `/funds/return/{paymentId}`; `remark1` = `Deposit #{paymentId}`.
- **Payment state** comes only from `check-order-status`:
  - paid = `txnStatus` is `COMPLETED`/`SUCCESS` **and** `status` (if present) is `SUCCESS`/`COMPLETED`
  - failed = `txnStatus` FAILED/ERROR/… or `status` FAILED/ERROR
  - anything else, including a missing `txnStatus`, stays pending and is never credited.
  A response with `"status": false` is a rejected query, not a payment state.
- **Amount**: must equal the expected amount exactly (to the paisa). A lower, higher
  or missing amount, or an `orderId` belonging to another order, holds the payment
  for **admin review** (`needs_review`). Review payments are never auto-credited;
  see [Payments held for review](#payments-held-for-review).
- **UTR** and the verified amount are stored on the payment and shown to the
  user and admin.
- **Webhook**: because no payload format or signature is documented, callbacks
  are treated as untrusted notifications. The handler accepts JSON, form or query
  data, reads only `order_id`/`orderId` to find the payment, ignores any status or
  amount in it, and settles from `check-order-status`. Unknown references return
  200 and trigger no API call. A reference-less request returns 400. If the status
  API is unreachable it returns 503 (cron retries every 5 minutes anyway). Tokens
  echoed in callbacks are masked before logging. GET and POST are both accepted,
  since the method is undocumented.
- **Create-order failures**:
  - Rejection (`status:false`, including `order_id already exists`): the payment is
    marked failed, its order reference is detached so no later callback can match it,
    and the customer sees a generic message.
  - Connection failure before sending: the payment is marked failed.
  - Timeout after sending, HTTP 5xx, non-JSON, or success without `payment_url`: it is
    not retried blindly. The server asks `check-order-status` for that `order_id`.
    If the gateway says the order is unknown, the payment is failed. Otherwise the
    payment stays pending (it expires after 30 min) and cron keeps verifying it.
    The customer is asked to try again, which creates a new order with a new `order_id`.
- **Currency**: the docs do not specify one. The gateway is offered only when the
  site currency equals the configured *Account currency* (default `INR`); amounts are
  never converted.
- **Test mode**: none is documented, so none is implemented.

### Setup

1. Admin → **Payment gateways** → **UPI (P2Gateway)** → *Configure*.
2. Paste the **API token** (stored encrypted, shown only masked, never sent to the browser).
3. Leave the endpoints at their documented defaults (HTTPS is enforced) and keep *Account currency* = `INR` (site currency must be INR).
4. Set min/max, display name, sort order → Status **Active** → Save.
5. Copy the displayed webhook URL `https://YOUR-DOMAIN/webhooks/p2gateway` into
   **P2Gateway Merchant Dashboard → Webhook URL**.
6. Make sure cron is running (it resolves missed callbacks).

### Live test (required before calling it production-ready)

Automated tests use a fake network. Before launch, run **one small real payment**
(e.g. ₹10) and confirm each step:

| # | Check | Where |
|---|---|---|
| 1 | Payment is created and you are redirected to a `p2gateway.in` page | Add Funds |
| 2 | Pay with a UPI app | phone |
| 3 | The callback arrives: a new row with result `Unsigned callback; API-verified: Credited` | Admin → Logs → Payment webhooks |
| 4 | Correct amount credited once; ledger shows one `deposit` | Admin → Transactions |
| 5 | UTR and verified amount recorded | Admin → Payments (References column) |
| 6 | Payment status `completed`; return page says "Payment confirmed" | user side |
| 7 | Re-send the callback (or reload the return page): result `Duplicate: already completed`, no new transaction | Logs / Transactions |
| 8 | Look at the raw callback payload in the webhook log. If P2Gateway documents a signature later, implement it exactly in `handleCallback()` and set `callbacksAreSigned()` to `true`. | Logs |
| 9 | Check `storage/logs/payment-*.log` for `check-order-status` bodies. If real responses differ from the documented shape, adjust `verifyPayment()` before going live. | server |

---

## Manual payments

Admin → Payment gateways → **Add manual method**. Each method has: name,
instructions, account/UPI ID/address (copy button), optional QR image, min/max,
optional fee, and "require screenshot".

User flow: choose method → see instructions/QR → pay externally → enter amount +
transaction reference (+ screenshot) → submit. Admin → **Manual payments** shows
pending requests with the proof image; approve (optionally correcting the
amount) or reject with a reason shown to the user.

Protections: a reference can only be submitted once per method (DB unique
index); max 5 pending requests per user; approving is transactional and
idempotent — a request can be approved only once, and approval goes through the
same `PaymentService::complete()` path (ledger entry, promo bonus, referral
commission). Proof images are stored outside the web root, re-encoded, and
served only to admins.

## Adding another gateway

1. Create `app/Payment/Gateways/YourGateway.php` extending `AbstractGateway`.
2. Implement `key()`, `label()`, `credentialFields()`, `optionFields()`,
   `createPayment()`, `handleCallback()` (verify signature first!), `verifyPayment()`.
3. Register it in `App\Payment\GatewayRegistry::GATEWAYS`.
4. Insert a row: `INSERT INTO payment_methods (gateway, name, status, created_at, updated_at) VALUES ('yourkey', 'Your Gateway', 'disabled', UTC_TIMESTAMP(), UTC_TIMESTAMP());`
5. Its webhook URL is automatically `/webhooks/yourkey`.
