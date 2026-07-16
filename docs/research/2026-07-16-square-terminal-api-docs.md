# Square Terminal API — Official Documentation Research

Research date: 2026-07-16. Primary source: developer.squareup.com (official docs and API reference). Items sourced from Square Developer Forums (staff answers) are flagged as such. Anything not verified verbatim against a doc page is flagged.

---

## 1. Terminal checkout lifecycle

**Doc URLs:**
- https://developer.squareup.com/docs/terminal-api/overview
- https://developer.squareup.com/docs/terminal-api/square-terminal-payments
- https://developer.squareup.com/reference/square/objects/TerminalCheckout

**States (`TerminalCheckout.status`):** `PENDING`, `IN_PROGRESS`, `CANCEL_REQUESTED`, `CANCELED`, `COMPLETED`

**Transitions:**
- `PENDING` — initial state on `CreateTerminalCheckout`. The request has been created but the terminal has not yet started the buyer flow.
- `IN_PROGRESS` — buyer is actively completing payment on the device.
- `CANCEL_REQUESTED` — transient state entered after `CancelTerminalCheckout` is called on a `PENDING`/`IN_PROGRESS` checkout; resolves to `CANCELED` if the buyer hadn't completed, or `COMPLETED` if the transaction already finished.
- `COMPLETED` — payment succeeded; `payment_ids[]` is populated (can be more than one payment).
- `CANCELED` — terminal state; `cancel_reason` is set (documented values include `TIMED_OUT`, `SELLER_CANCELED`, and `BUYER_CANCELED` — `TIMED_OUT` and `SELLER_CANCELED` verified verbatim in the reference; `BUYER_CANCELED` appears in the ActionCancelReason enum and sandbox test scenarios).
- A `PENDING` checkout that exceeds `deadline_duration` is automatically `CANCELED` with `cancel_reason: TIMED_OUT`.

**What a POS integration must handle:**
- `COMPLETED` and `CANCELED` are the only terminal states; treat everything else as in-flight.
- **A `COMPLETED` checkout might not equal the requested total.** Official warning: "A COMPLETED checkout might not have collected the exact total you requested. You should always check the Payment object to determine the actual amount collected." (Relevant with tips, partial authorization, gift cards.)
- `payment_ids` is an array — a checkout can produce multiple payments; reconcile all of them.
- **TerminalCheckout objects are deleted after 30 days.** "The Payment object serves as your permanent record." Persist `payment_ids`/`order_id` in WooCommerce order meta immediately on completion.
- Race at timeout boundary (documented): "A Terminal might be completing a payment at the threshold of the timeout. When this happens, the checkout might become CANCELED prior to becoming COMPLETED." — i.e. after seeing `CANCELED` at the deadline boundary, verify no payment was actually taken before releasing the order.

**Common integrator mistakes flagged in docs/forums:** treating `CANCEL_REQUESTED` as final (it is not — checkouts can still complete); assuming the checkout amount equals the collected amount; relying on TerminalCheckout as the payment record past 30 days.

---

## 2. Tracking checkout status: webhooks vs polling

**Doc URLs:**
- https://developer.squareup.com/docs/terminal-api/overview
- https://developer.squareup.com/docs/webhooks/overview
- https://developer.squareup.com/reference/square/terminal-api/get-terminal-checkout

**Official recommendation:** webhooks first. "Your POS application should monitor the state of any Terminal checkout requests by subscribing to the Terminal API webhook events." Polling is the documented fallback: "If the POS application isn't listening for webhook notifications, it can get the checkout result using the Terminal API" (`GetTerminalCheckout`, `GET /v2/terminals/checkouts/{checkout_id}`).

**Relevant webhook events:** `terminal.checkout.created`, `terminal.checkout.updated` (full `TerminalCheckout` object in the payload). Also `terminal.refund.created/updated`, `terminal.action.created/updated`.

**Webhook delivery caveats (see §6):** no ordering guarantee, at-least-once delivery, usually <60s latency. Because ordering is not guaranteed, always read `status` from the delivered object (or re-fetch via `GetTerminalCheckout`) rather than inferring state from event sequence, and ignore events older than the state you've already recorded (compare `updated_at`).

**Rate limits:** Square does not publish numeric rate limits. Official guidance is to watch for HTTP 429 / `RATE_LIMITED` and retry with exponential backoff + jitter (https://developer.squareup.com/docs/build-basics/general-considerations/handling-errors#rate-limiting-errors). Forum staff answers historically cite roughly 10 QPS per application as a soft ceiling — **not an official documented number**. Practical implication: poll `GetTerminalCheckout` at a modest interval (e.g. every 2–5 s per active checkout), not sub-second.

**Safe combination pattern (synthesized from official guidance):**
1. Subscribe to `terminal.checkout.updated`; verify signature; dedupe by `event_id`; respond 2xx immediately and process async.
2. Poll `GetTerminalCheckout` as a backstop (webhooks are at-least-once but can be delayed/missed for 24h+).
3. Use the Events API (https://developer.squareup.com/docs/events-api/overview) to recover missed notifications after downtime.
4. Make the status handler idempotent so webhook + poll racing on the same transition is harmless.

---

## 3. Canceling a checkout

**Doc URLs:**
- https://developer.squareup.com/reference/square/terminal-api/cancel-terminal-checkout
- https://developer.squareup.com/docs/terminal-api/square-terminal-payments
- https://developer.squareup.com/docs/terminal-api/dismiss-checkouts-and-refunds

**`CancelTerminalCheckout`:** `POST /v2/terminals/checkouts/{checkout_id}/cancel`, permission `PAYMENTS_WRITE`. "Cancels a Terminal checkout request if the status of the request permits it" — cancelable only while `PENDING` or `IN_PROGRESS`. On success the checkout returns with `status: CANCELED`, `cancel_reason: SELLER_CANCELED`.

**If payment already completed:** the checkout goes `CANCEL_REQUESTED` and then `COMPLETED` if the buyer already finished — cancellation is a request, not a guarantee. Your integration must handle a cancel attempt that resolves to `COMPLETED` (then decide: keep the payment or refund it).

**Documented race conditions:**
- Timeout-boundary race (see §1): a checkout may become `CANCELED` even though a payment was captured moments before; verify the Payment object.
- E-money limitation (Japan): "Using CancelTerminalCheckout to cancel an e-money payment after getting an error on the Square Terminal is currently not supported."
- Forum-reported (not formal docs): checkouts can get stuck in `CANCEL_REQUESTED` when a device is offline — handle by also enforcing your own client-side timeout and reconciling via `GetTerminalCheckout`.

**Timeout control:** `deadline_duration` (RFC 3339 duration, e.g. `PT5M`) on the checkout. Reference doc: **default 5 minutes, maximum 5 minutes** (i.e. you can only shorten it). Only `PENDING` checkouts auto-cancel with `TIMED_OUT`; an `IN_PROGRESS` checkout is not killed mid-payment by the deadline.

**Dismiss vs cancel:** `DismissTerminalCheckout` (`POST /v2/terminals/checkouts/{checkout_id}/dismiss`) returns the terminal to the idle screen and resolves the checkout according to its actual payment state (captures an authorized payment, cancels an unauthorized one, ignores if already complete). Docs recommend dismiss over cancel when a buyer abandons a checkout, "because it handles the checkout's current state automatically." There is also `DismissTerminalRefund` (`POST /v2/terminal/refunds/{terminal_refund_id}/dismiss`).

---

## 4. CreateTerminalCheckout parameters for quality integrations

**Doc URLs:**
- https://developer.squareup.com/reference/square/terminal-api/create-terminal-checkout
- https://developer.squareup.com/reference/square/objects/TerminalCheckout
- https://developer.squareup.com/reference/square/objects/TipSettings

**Endpoint:** `POST /v2/terminals/checkouts`, permission `PAYMENTS_WRITE`.

**Top-level request:**
- `idempotency_key` (string, **required**, 1–64 chars, unique per request) — reuse the same key when retrying a failed/ambiguous create so you never show two checkouts on the device.
- `checkout` (TerminalCheckout, required).

**TerminalCheckout fields that matter:**
| Field | Notes |
|---|---|
| `amount_money` | Required. Total including tax. |
| `device_options.device_id` | Required. From DeviceCodes pairing (§5). |
| `reference_id` | Max 40 chars — put the WooCommerce order ID/number here; shows in Square Dashboard. |
| `note` | Max 500 chars; shows on payment record. |
| `order_id` | Link to a Square Order (enables itemized cart display). |
| `deadline_duration` | RFC 3339; default and max 5 minutes; `PENDING` → `TIMED_OUT`. |
| `payment_type` | Defaults `CARD_PRESENT`. |
| `customer_id`, `team_member_id` | Optional associations. |
| `app_fee_money` | Platform fee; must be ≤ 90% of total; requires app-fee approval (error `INVALID_FEES` if too high). |
| `statement_description_identifier` | Max 20 chars, card statement descriptor. |

**`payment_options` (PaymentOptions):**
- `autocomplete` (bool) — `true` = capture immediately; `false` = delayed capture (complete later via Payments API `CompletePayment`).
- `delay_duration` (RFC 3339) — how long an uncaptured payment is held before `delay_action` runs.
- `delay_action` — what happens when the delay expires (e.g. CANCEL).
- `accept_partial_authorization` (bool) — allow gift-card partial auth; if you enable this you must handle a `COMPLETED` checkout that collected less than the total (check the Payment objects, collect remainder separately).

**`device_options` (DeviceCheckoutOptions):**
- `device_id` (required), `skip_receipt_screen` (bool), `collect_signature` (bool), `show_itemized_cart` (bool, requires an attached order).
- `tip_settings` (TipSettings): `allow_tipping` (default false), `separate_tip_screen` (tip before signature), `custom_tip_field`, `tip_percentages` (up to 3 ints, 0–100, default [15, 20, 25]), `smart_tipping` (overrides percentages; region-aware: <$10 fixed amounts $0/$0.50/$1/$2, ≥$10 percentages 0/5/10/15; supported in AU, CA, IE, UK, US).

**Flagged mistakes:** generating a fresh idempotency key on retry (creates duplicate checkouts); assuming tip-inclusive total equals `amount_money`; enabling `accept_partial_authorization` without handling under-collection.

---

## 5. Device pairing and device status

**Doc URLs:**
- https://developer.squareup.com/docs/terminal-api/pos-integration (also /docs/terminal-api/integrate-square-terminal)
- https://developer.squareup.com/reference/square/devices-api
- https://developer.squareup.com/reference/square/devices-api/webhooks
- https://developer.squareup.com/docs/terminal-api/advanced-features (Terminal actions)

**Pairing flow (DeviceCodes API):**
1. `CreateDeviceCode` with `idempotency_key`, `device_code.product_type: "TERMINAL_API"` (required value), `device_code.location_id`, optional `device_code.name`. Requires `DEVICE_CREDENTIAL_MANAGEMENT` OAuth permission.
2. Display the returned code; the seller enters it on the Square Terminal ("Sign in with device code").
3. Detect pairing via the **`device.code.paired` webhook** (fires when "a Square Terminal has been paired... and the device_id of the paired Square Terminal is available") or by polling `GetDeviceCode`.
4. On success, `DeviceCode.device_id` is the ID to use in `device_options.device_id`.

**DeviceCode statuses:** `UNPAIRED` → `PAIRED` (also `UNKNOWN`, `EXPIRED`). Codes expire (`pair_by` timestamp; example shows a ~24h window — treat exact window as per response field, not a hardcoded constant).

**Documented gotcha:** device codes generated in the **Square Dashboard will NOT work** for Terminal API pairing — only API-generated codes with `product_type: TERMINAL_API` pair correctly. This is a common integrator mistake.

**Multiple terminals:** create one device code per terminal/location; store each `device_id` with a human-readable `name`; enumerate via Devices API `ListDevices` / `GetDevice`.

**Checking a terminal is online:** Terminal actions `CreateTerminalAction` with `type: "PING"` (action types: `PING`, `QR_CODE`, `SAVE_CARD`, `SIGNATURE`, `CONFIRMATION`, `RECEIPT`, `DATA_COLLECTION`, `SELECT`). A PING that reaches an online device completes and returns read-only `device_metadata` (battery level, OS version, network connection details). Actions share the checkout state machine (`PENDING`/`IN_PROGRESS`/`CANCEL_REQUESTED`/`CANCELED`/`COMPLETED`), `deadline_duration` default/max 5 minutes, `PENDING` → `TIMED_OUT`. Track via `terminal.action.updated`. A PING that times out ⇒ device offline. Requires `PAYMENTS_READ`/`PAYMENTS_WRITE`. (Terminal actions are documented as Beta.)

---

## 6. Webhook Subscriptions API

**Doc URLs:**
- https://developer.squareup.com/docs/webhooks/overview
- https://developer.squareup.com/docs/webhooks/webhook-subscriptions-api
- https://developer.squareup.com/reference/square/webhook-subscriptions-api
- https://developer.squareup.com/docs/webhooks/step3validate (signature validation)
- https://developer.squareup.com/docs/webhooks/movetoprod

**Endpoints:** `CreateWebhookSubscription`, `ListWebhookSubscriptions`, `RetrieveWebhookSubscription`, `UpdateWebhookSubscription`, `DeleteWebhookSubscription`, `UpdateWebhookSubscriptionSignatureKey` (rotates the key — docs suggest 90-day rotation), `TestWebhookSubscription` (sends a test event to validate the URL), `ListWebhookEventTypes`.

**Subscription fields:** `id`, `name`, `enabled`, `event_types[]`, `notification_url` (must be HTTPS), `api_version`, `signature_key` (returned on create; retrieve/rotate via the API).

**Auth caveat (documented):** the Webhook Subscriptions API "must use the application's **personal access token**" — OAuth merchant tokens are not supported. For a WooCommerce plugin this means webhook subscriptions are managed with the app's own access token.

**Signature verification:** header `x-square-hmacsha256-signature`; algorithm = Base64( HMAC-SHA256( key = subscription `signature_key`, message = **notification URL string + raw request body** ) ); compare constant-time. Square SDK helpers: PHP `WebhooksHelper::verifySignature()`, Node `WebhooksHelper.verifySignature()`, Python `is_valid_webhook_event_signature()`, etc. **Common mistakes flagged:** using a parsed/re-serialized body instead of the raw bytes; verifying against a URL that differs from the exact registered notification URL (scheme/trailing slash/query mismatch); non-constant-time comparison (docs explicitly warn about timing attacks).

**`api_version` interaction:** each subscription pins its own `api_version`, independent of the application default — it "determines which event payload structure gets sent." The chosen version must support the event types subscribed.

**Delivery semantics (official):**
- Retries with exponential backoff on non-2xx, ~doubling intervals, up to **11 attempts over 24 hours**; "After 24 hours, the notification is discarded." First retry ~10 s / ~1 min scale, growing to 8-hour gaps.
- Retried notifications carry `square-retry-number` and `square-retry-reason` headers.
- **"There's no guarantee of the delivery order of event notices."** Dedupe on `event_id`; expect duplicates (at-least-once).
- Latency SLA-ish statement: "In most cases, event notifications arrive in well under 60 seconds."
- Respond 2xx "as soon as possible" — do work async, not inline.
- Missed events beyond 24h: recover with the Events API (`/docs/events-api/overview`).
- Webhook source IPs (if firewalled): production 54.245.1.154, 34.202.99.168; sandbox 54.212.177.79, 107.20.218.8. (Verify current list before hardcoding.)

**Sandbox vs production:** subscriptions, credentials, signature keys, and notification URLs are all **per-environment** — a sandbox subscription never fires for production events. Production API base is `https://connect.squareup.com`, sandbox `https://connect.squareupsandbox.com`. Create separate subscriptions in each and store both signature keys.

---

## 7. Sandbox testing

**Doc URLs:**
- https://developer.squareup.com/docs/devtools/sandbox/testing#terminal-checkout-test-device-ids
- https://developer.squareup.com/docs/devtools/sandbox/testing#terminal-interac-refund-test-device-ids

No physical device is needed in sandbox: pass a magic `device_id` in `device_options.device_id` and the sandbox simulates the full state progression (including webhooks).

**Checkout test device IDs:**
| device_id | Simulates |
|---|---|
| `9fa747a2-25ff-48ee-b078-04381f7c828f` | Success — credit card (≤ $25 USD) |
| `22cd266c-6246-4c06-9983-67f0c26346b0` | Success with 20% tip (≤ $25 USD) |
| `4mp4e78c-88ed-4d55-a269-8008dfe14e9` | Success — Square gift card (≤ $25 USD) |
| `388b5a08-a77c-48ef-ad2a-4a790e6f2789` | Success — Interac (CAD only) |
| `2b0b734b-b187-47f0-9d6f-288745210bdb` | Interac with 20% tip (CAD only) |
| `19a01fbd-3dcd-4d9f-a499-a641684af745` | eMoney/FeLiCa approval (JP) |
| `819f8d79-961e-4097-8f70-ef70b3e7db28` | Afterpay approval (≤ $25 USD) |
| `cae0ee02-f83b-11ec-b939-0242ac120002` | PayPay QR (JP locations) |
| `841100b9-ee60-4537-9bcf-e30b2ba5e215` | Buyer cancels (`CANCELED` / `BUYER_CANCELED`) |
| `0a956d49-619a-4530-8e5e-8eac603ffc5e` | Immediate `TIMED_OUT` |
| `da40d603-c2ea-4a65-8cfd-f42e36dab0c7` | Offline terminal — stays `PENDING`; you must cancel (exercises the cancel path) |

**Interac refund test device IDs:** `f72dfb8e-4d65-4e56-aade-ec3fb8d33291` (success), `aafea9fa-350c-4ab2-b033-b2fbb672e712` (buyer cancel), `e371fb66-29a2-45a6-a928-f8de0e864242` (timeout), `7647344e-aea2-4cff-ac53-513644de434d` (offline, developer-cancelable).

**Sandbox notes:** amounts above $25 USD on the success IDs are declined — useful for exercising failure handling; sandbox webhook subscriptions/keys are entirely separate from production (§6); device pairing (DeviceCodes) is bypassed in sandbox since the magic IDs stand in for paired devices.

---

## 8. Refunds on Terminal payments

**Doc URLs:**
- https://developer.squareup.com/docs/terminal-api/square-terminal-refunds
- https://developer.squareup.com/reference/square/refunds-api/refund-payment
- https://developer.squareup.com/reference/square/terminal-api/create-terminal-refund

**Rule:** use the Refunds API (`RefundPayment`, `POST /v2/refunds`) for everything **except** payments that require card presence. The decision field is **`Payment.card_details.refund_requires_card_presence`** — when `true` (Canadian Interac), you must use Terminal refunds (`CreateTerminalRefund`) so the buyer taps the card on the device.

**For a WooCommerce integration:** wire the standard WooCommerce refund flow to `RefundPayment` using the stored `payment_id` (with its own `idempotency_key`, `amount_money`, `payment_id`, optional `reason`). If `refund_requires_card_presence` is `true`, block the online refund and instruct the merchant to run a card-present Terminal refund. Permissions: `PAYMENTS_WRITE` (+ `PAYMENTS_READ`).

**Terminal refund specifics (Interac):** payment must be `COMPLETED`, CAD Interac, ≤ 365 days old, refund ≤ remaining unrefunded amount (`Payment.total_money − Payment.refunded_money`); refundable from any of the seller's locations; multi-card checkouts are refunded per card (one request per payment). `TerminalRefund` states mirror checkouts (`PENDING`/`IN_PROGRESS`/`COMPLETED`/`CANCELED`); on completion `TerminalRefund.refund_id` links to the Refunds API record. Track via `terminal.refund.created/updated` and `payment.updated` (increments `refunded_money`).

**30-day caveat:** Terminal checkout objects vanish after 30 days — for older refunds, look up the Payment (e.g. by stored `payment_id` or ListPayments), not the TerminalCheckout.

---

## 9. Error handling best practices

**Doc URLs:**
- https://developer.squareup.com/docs/build-basics/general-considerations/handling-errors
- https://developer.squareup.com/reference/square/objects/Error
- https://developer.squareup.com/docs/build-basics/common-api-patterns/idempotency

**Error object:** `errors[]` with `category`, `code`, `detail` (human-readable, for developers), `field` (offending request field).

**Categories:** `API_ERROR` (Square-side — retriable), `AUTHENTICATION_ERROR` (401/403 — not retriable without fixing credentials; codes `UNAUTHORIZED`, `ACCESS_TOKEN_EXPIRED`, `ACCESS_TOKEN_REVOKED`, `INSUFFICIENT_SCOPES`, `FORBIDDEN`, `CLIENT_DISABLED`), `INVALID_REQUEST_ERROR` (400 — fix the request, do not retry as-is), `RATE_LIMIT_ERROR` (429, code `RATE_LIMITED` — retriable with backoff), `PAYMENT_METHOD_ERROR`, `REFUND_ERROR` (business declines — surface to the merchant, don't blind-retry).

**Official retry guidance:** on 429 (and 5xx), "use a retry mechanism with an exponential backoff schedule," plus jitter to avoid thundering-herd. For network timeouts or ambiguous failures on POSTs, **retry with the same `idempotency_key`** — Square's idempotency guarantee makes the retry safe (the duplicate returns the original result instead of creating a second checkout/refund). Never mint a new idempotency key for a retry of the same logical operation.

**Terminal-specific errors seen in docs:** `INVALID_FEES` (app_fee_money > 90% of total), `INVALID_LOCATION` (regional payment restrictions). A create against an unpaired/unknown `device_id` fails with `INVALID_REQUEST_ERROR`/`NOT_FOUND`-class errors (exact code not enumerated on the reference page — flag: verify empirically).

---

## 10. API versioning

**Doc URLs:**
- https://developer.squareup.com/docs/build-basics/versioning-overview
- https://developer.squareup.com/docs/changelog/connect (release notes / latest version)

**Mechanics:** versions are named `YYYY-MM-DD` (release date). Each application has a **default API version** set on the Developer Console Credentials page (separately for sandbox and production); every request uses it unless overridden per request with the `Square-Version: YYYY-MM-DD` header. Responses echo the applied `Square-Version`. SDK releases are pinned to a specific Square-Version.

**Recommendation for a plugin:** always send an explicit `Square-Version` header (or use a pinned SDK) rather than relying on the app default — the Console default can drift when Square updates app defaults, and the API-version an integration is tested against should be the one it runs against. Check the changelog before bumping; the current recommended version is simply the latest dated release in the changelog (the docs deliberately don't hardcode one — flag: pick the latest at implementation time, e.g. from https://developer.squareup.com/docs/changelog/connect).

**Webhook interaction:** each webhook subscription pins its own `api_version` (independent of the application default and the header you send on requests); it controls the event payload schema. The subscription's `api_version` must support the subscribed event types. Keep the subscription `api_version` and the plugin's request `Square-Version` aligned so the `TerminalCheckout` shape you parse from webhooks matches what you parse from API responses.

---

## Cross-cutting checklist for a best-in-class WooCommerce integration

1. Pair via `CreateDeviceCode` (`product_type: TERMINAL_API`) + `device.code.paired` webhook; never Dashboard codes.
2. Create checkouts with a stored idempotency key per WooCommerce order attempt; `reference_id` = order number; retries reuse the key.
3. Subscribe to `terminal.checkout.updated` / `terminal.refund.updated` / `device.code.paired` via Webhook Subscriptions API (personal access token), verify `x-square-hmacsha256-signature` against notification URL + raw body, dedupe by `event_id`, respond 2xx fast.
4. Poll `GetTerminalCheckout` as a backstop with backoff; reconcile with Events API after downtime.
5. On `COMPLETED`, read the Payment object(s) for the true amount (tips/partial auth) and persist `payment_ids` (TerminalCheckout dies in 30 days).
6. Cancel via `CancelTerminalCheckout` but handle `CANCEL_REQUESTED → COMPLETED`; prefer `DismissTerminalCheckout` for abandoned checkouts; enforce/respect the 5-minute `deadline_duration`.
7. Refund via `RefundPayment` unless `refund_requires_card_presence` is true (Interac → `CreateTerminalRefund`).
8. Use PING Terminal actions for a "terminal online" health check before sending a checkout.
9. Sandbox-test every state with the magic device IDs, including timeout and offline-terminal paths.
10. Pin `Square-Version` explicitly and align it with each webhook subscription's `api_version`.
