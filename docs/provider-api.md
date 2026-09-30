# SMM provider API integration

The order engine talks to providers only through
`App\Providers\ProviderAdapterInterface`, so any number of providers can be
connected and new API styles can be added without touching the order logic.

## The standard API v2 adapter

Most SMM panels expose the de-facto "API v2": one URL, form-encoded `POST`,
parameters `key` and `action`, JSON responses, errors as `{"error": "…"}`.

| Operation | Request | Expected response |
|---|---|---|
| Services | `action=services` | `[{"service":1,"name":"…","type":"Default","category":"…","rate":"0.90","min":"50","max":"10000","refill":true,"cancel":true,"dripfeed":false}]` |
| Add order | `action=add&service=&link=&quantity=` (+ `runs, interval, comments, usernames, username, answer_number, keywords`) | `{"order": 23501}` |
| Status | `action=status&order=ID` | `{"charge":"0.27","start_count":"3572","status":"Partial","remains":"157","currency":"USD"}` |
| Multi-status | `action=status&orders=1,2,3` | `{"1":{…},"2":{"error":"Incorrect order ID"}}` |
| Refill | `action=refill&order=ID` | `{"refill":"1"}` |
| Refill status | `action=refill_status&refill=ID` | `{"status":"Completed"}` |
| Cancel | `action=cancel&orders=1,2` | `[{"order":1,"cancel":1},{"order":2,"cancel":{"error":"…"}}]` |
| Balance | `action=balance` | `{"balance":"100.84","currency":"USD"}` |

**No endpoints are invented**: the adapter only calls the exact API URL you
enter. If a provider does not support an operation (e.g. refill), the provider
returns an error and it is handled like any other rejection.

### Adding a provider

Admin → **Providers** → Add provider:
- **API URL** — copy it exactly from the provider's API page, e.g. `https://smmexporter.in/api/v2`. Spaces and line breaks are removed. HTTPS is required unless you tick *Allow plain HTTP*, which sends the key unencrypted, so use it only for providers without HTTPS.
- **API key** — stored encrypted; never shown again (only masked).
- **Currency / exchange rate** — `1 provider unit = X site units`. Used when importing and syncing prices (e.g. provider in USD, site in INR → `83.20`).
- Save → the connection is tested immediately with `action=balance`.

Then **Fetch services** → **Browse & import**: tick services, choose a markup %
and whether categories are created from the provider's names, and import.
Imported services with **auto-sync** keep price (`provider rate × exchange rate
× (1 + markup)`), min and max aligned with the provider; services the provider
removes are disabled automatically (cron `provider_sync`, every 6 h).

You can also create a service by hand and set "Provider" + "Provider service ID".

### Per-provider configuration for non-standard panels

Some panels rename parameters or lack multi-status. Put a JSON object in the
provider's **Advanced: adapter configuration** field; unspecified keys keep the
defaults:

```json
{
  "method": "POST",
  "key_param": "key",
  "action_param": "action",
  "actions": {"add": "add", "status": "status", "services": "services", "balance": "balance",
              "refill": "refill", "refill_status": "refill_status", "cancel": "cancel"},
  "params": {"service": "service", "link": "link", "quantity": "quantity", "runs": "runs",
             "interval": "interval", "comments": "comments", "usernames": "usernames",
             "username": "username", "answer_number": "answer_number", "keywords": "keywords"},
  "multi_status": true,
  "multi_status_param": "orders",
  "multi_status_max": 100,
  "cancel_param": "orders",
  "extra": {},
  "key_in": "param",
  "key_header": "X-Api-Key",
  "user_agent": ""
}
```

`key_in`: `param` sends the key as a form field (standard). `bearer` sends it as
`Authorization: Bearer <key>`. `header` sends it in the header named by
`key_header`. `user_agent` overrides the User-Agent header, for providers whose
firewall filters on it.

Example — a panel using `api_token` and no multi-status: `{"key_param":"api_token","multi_status":false}`.

### Response formats accepted

Panels that follow the API v2 documentation still differ in small ways. The
adapter (`App\Providers\ResponseParser`) accepts every unambiguous variant:

- **balance:** `{"balance":"100.84","currency":"USD"}`, a JSON number
  (`100.84`, `1e-5`), thousands separators (`"1,234.56"`, `"1.234,56"`),
  currency symbols or codes (`"$12.50"`, `"12.50 USD"`, `"₹ 1,234"`), keys in
  any case (`Balance`), wrappers (`{"data":{"balance":…}}`,
  `{"balance":{"amount":…,"currency":…}}`) and a bare number body. The currency
  comes from `currency`, from the amount text, or defaults to USD.
- **services:** a JSON list, `{"services":[…]}`/`{"data":[…]}`, or an object
  keyed by service id. It also accepts `price` for `rate`, `id`/`service_id`
  for `service`, and numbers with separators or symbols.
- **Noise:** a UTF-8 BOM or PHP notices printed before the JSON are skipped.
- **Errors:** `{"error":…}`, `{"errors":…}`, `{"success":false,"message":…}` and
  `{"status":"fail","message":…}` are reported as provider errors. A bare
  `{"status":"Fail"}` is an *order* status (it maps to cancelled), not an error.

Provider-side **"Subscriptions"** services (username / min / max / posts /
delay / expiry) are imported as **post-based Subscriptions services**. The
panel sends `action=add` with `username, min, max, posts, old_posts, delay,
expiry` (expiry as `d/m/Y`), reserves the maximum cost, reads `posts`,
`charge` and the status (`Active`, `Paused`, `Completed`, `Expired`,
`Canceled`) from `action=status`, and settles the reserve once (see
`docs/features.md` → Post-based subscriptions).

**Price protection:** each price sync also compares the provider's new cost
with the previous one and applies Settings → Orders → *Provider price
protection* (block, auto-adjust or disable services that would sell below cost
+ margin). The events are listed in Admin → Services → Price changes.

### Troubleshooting

The provider card shows **Connected**, **Error**, **Never checked**,
**Syncing…** (a catalog fetch is running) or **Disabled**. Errors never include
the API key: keys are redacted from messages and from **Providers → API
logs**, which keeps the full response for each call.

| Message | Meaning / fix |
|---|---|
| `Unexpected balance response: no numeric "balance" field. Received a JSON object with keys: …` | The provider answered with JSON that has no balance. The listed keys and preview show what it sent. Usually a wrong `action` name (set `actions.balance` in the config) or an account problem on the provider side. The full body is in API logs. |
| `Provider error: Invalid API key` (or similar) | The provider rejected the key. Re-copy it. Some panels bind keys to an IP, so allow your server's IP. |
| `The provider returned an HTML page instead of JSON (HTTP 403)` | Wrong URL (usually missing `/api/v2`), or Cloudflare/a firewall blocks server requests. Ask the provider to allow your server IP, or set `user_agent`. |
| `The API URL redirects (HTTP 301) to https://…` | Enter the final URL shown (often `https://` or `www.`). Redirects are not followed, so the key is never sent to another host. |
| `Invalid (non-JSON) response, HTTP 500: "…"` | The provider's API is failing. Try again later. |
| `Network error: …` | DNS, TLS or timeout. Check the domain, or raise *Timeout*. |
| `Unexpected services response: none of the N items has the API v2 fields service, name and rate` | The panel uses different field names. Contact the provider or use a custom adapter (below). |

### Status mapping

| Provider says | Local status | Money |
|---|---|---|
| Pending / Queued | pending | — |
| Processing | processing | — |
| In progress / Active | in_progress | — |
| Completed | completed | — |
| Partial | partial | refund `charge × remains ÷ total` (once) |
| Canceled / Cancelled / Refunded / Fail | cancelled | refund everything not yet refunded (once) |
| anything unrecognised | unchanged, error logged | — |

Refund references are unique per order and kind (`order:{id}:refund:partial`
etc.), so running the sync any number of times can never refund twice.

## Failure handling (why orders are never double-charged or double-sent)

`App\Core\HttpClient` distinguishes where a failure happened:

| Situation | Classification | What the engine does |
|---|---|---|
| Provider replied `{"error": …}` / HTTP 4xx | **rejected** | order `failed`, full refund, user notified |
| DNS failure / connection refused / TLS handshake failed / HTTP 429 | **unreachable** (request never processed) | stays `queued`; cron retries up to 8 times, then fails + refunds |
| Timeout after sending, connection reset, 5xx, non-JSON, missing order id | **unknown** | `submit_state = unknown`, `needs_attention = 1`, admin alerted; **never** auto-retried or auto-refunded |

Unknown orders appear highlighted in Admin → Orders with three actions:
*Mark submitted* (enter provider order ID), *Resubmit* (only if the provider has
no such order) or *Fail & refund*. Orders stuck in `submitting` for 10 minutes
(PHP process killed mid-request) become `unknown` the same way.

Submission is claimed atomically (`UPDATE … WHERE submit_state='queued'`), so
the web request and the cron job can never both send the same order. Read-only
calls (status, services, balance) retry once if the request provably never left
the server; state-changing calls (add, cancel, refill) never retry.

Every call is logged to `provider_logs` (Admin → Providers → API logs) without
the API key.

## Adding a completely different API style

1. Implement `ProviderAdapterInterface` in `app/Providers/YourAdapter.php`.
   Throw `ProviderException` with the correct kind (`KIND_REJECTED`,
   `KIND_UNREACHABLE`, `KIND_UNKNOWN`) — this is what keeps money safe.
2. Register it in `ProviderFactory::ADAPTERS`.
3. It appears in the provider form's "API format" select.
