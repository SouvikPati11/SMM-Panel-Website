# Payment gateways

Admin → **Payment gateways** lists every method. Automatic gateways (OxaPay,
Cryptomus, P2Gateway) share one interface; manual methods (UPI, bank, crypto
address…) are unlimited and fully configurable.

## How crediting works (applies to every gateway)

```
User clicks "Continue to payment"
  → server validates amount limits + promo code, creates `payments` row (pending)
  → server creates the invoice at the gateway, stores its reference, redirects the browser
Gateway calls POST /webhooks/{gateway}
  → raw request stored in webhook_logs
  → signature verified (reject → HTTP 401)
  → payment located by gateway reference, order_id cross-checked ("PAY-{id}")
  → if "paid": server re-queries the gateway API; credits only if the API agrees
    AND currency matches AND paid amount ≥ expected amount
  → PaymentService::complete(): row lock + idempotent ledger entry "payment:{id}"
Cron `payments` every 5 min re-checks pending invoices (missed webhooks) and expires old ones.
```

Guarantees:
- The browser return URL (`/funds/return/{id}`) **never** credits anything; it only displays status.
- The amount credited is the amount stored when the invoice was created — never a value from the callback or the browser.
- Duplicate or replayed callbacks are no-ops (`status = completed` check under `SELECT … FOR UPDATE` + unique ledger reference).
- Amount/currency mismatches are held (not credited) and flagged in Admin → Payments with an email alert.
- Credentials are encrypted in the database with `APP_KEY` (libsodium) and are write-only in the admin UI.

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

## P2Gateway.in — placeholder (NOT implemented)

Implementation: `app/Payment/Gateways/P2GatewayGateway.php`

During development **no official public API documentation for P2Gateway.in
could be obtained** (the site was unreachable from the build environment and no
developer docs are indexed). Following the rule "never invent endpoints,
parameters, authentication or signature algorithms", the adapter is an isolated
placeholder:

- `isImplemented()` returns `false`, so the method is never offered to users and cannot be enabled;
- credentials can already be stored (encrypted);
- any webhook to `/webhooks/p2gateway` is logged and ignored — it can never credit a wallet.

### What is needed to complete it

Request from P2Gateway.in (merchant support / developer portal):

1. **Base URL(s)** for production and sandbox, and API version.
2. **Authentication**: header names / key + secret / token flow; whether requests must be signed.
3. **Create payment endpoint**: method, URL, request fields (amount format and currency — INR?, order reference, customer fields, return URL, callback URL), and response fields (payment/redirect URL or UPI intent/QR, transaction reference, expiry).
4. **Callback/webhook**: HTTP method, content type, full field list, every possible status value and which ones mean "paid".
5. **Signature algorithm** for callbacks (e.g. HMAC-SHA256 over which fields/order, which secret) and a sample signed payload for testing.
6. **Status query endpoint** to verify a payment server-side (required — we never credit on a callback alone).
7. Amount limits, retry behaviour for callbacks, and IP addresses callbacks come from.
8. Sandbox credentials.

Then implement `createPayment()`, `handleCallback()` and `verifyPayment()`
following `OxaPayGateway` as the template (same return types), set
`isImplemented()` to `true`, map statuses in a `mapStatus()` method, and add
tests to `tests/PaymentTest.php` modelled on the OxaPay ones (valid, invalid
signature, duplicate, failed, amount mismatch).

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
