# Square OAuth and Terminal Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Deliver a one-button managed Square connection, automatic location and Terminal discovery, guided Terminal pairing, backward-compatible manual credentials, and identity-safe payment reconciliation.

**Architecture:** wcpos.com is a versioned Square PKCE OAuth broker and webhook relay; it owns the durable per-connection authorization registry, encrypted refresh-token vault, fenced refresh/disconnect state machines, per-token revocation secret, and pinned relay delivery. Square issues rotating single-use refresh tokens and 24-hour access tokens; refresh tokens never leave the broker, while WordPress stores only short-lived access credentials encrypted. WordPress calls Square directly for locations/devices/checkouts and records an immutable connection identity on every payment attempt. Polling and the sweeper remain authoritative; relayed webhooks reduce latency.

**Tech Stack:** Next.js 16 route handlers, TypeScript 6, Zod 4, Upstash Redis, Node crypto/https, Vitest; WordPress/WooCommerce PHP 8.1+, Square PHP SDK 45.1, vanilla JavaScript, node:test, PHPUnit 10, PHPCS.

## Global Constraints

- Implement the approved design in docs/superpowers/specs/2026-07-21-square-oauth-device-onboarding-design.md.
- Use separate worktrees and companion PRs for wcpos/wcpos-com and wcpos/square-terminal-for-woocommerce.
- Do not add a JavaScript or PHP dependency unless the existing platform cannot implement a required primitive.
- The Square application requests exactly MERCHANT_PROFILE_READ, PAYMENTS_READ, PAYMENTS_WRITE, DEVICES_READ, and DEVICE_CREDENTIAL_MANAGEMENT.
- Sandbox and production credentials, Redis prefixes, locations, devices, and webhook keys are isolated.
- Existing manual installations remain manual until an administrator explicitly switches.
- Managed OAuth never silently falls back to a saved manual token.
- Never place a Square access token, refresh token, authorization code, verifier, Device Code, raw webhook body, or relay secret in a browser URL or log.
- Never return a Square refresh token to WordPress or persist one in any WordPress option, transient, request, rendered payload, or log.
- Every auth, payment, persistence, and race-condition change is test-first.
- Keep each delivery PR reviewable; if a non-mechanical PR exceeds roughly 400 changed lines, split it at the task boundaries below rather than combining unrelated increments.
- Do not expose Connect to Square until the compatible broker is deployed and capabilities advertises protocol version 1.
- Before every commit run the full lint command for the changed repository. Before every push run the full test suite, type/build checks where available, and git diff --check.

## Repository and PR Sequence

1. **wcpos-com PR A — broker authorization core:** Tasks 1–3.
2. **wcpos-com PR B — webhook relay:** Task 4; depends on PR A interfaces.
3. **square-terminal PR C — local connection foundation:** Tasks 5–6; Connect remains capability-gated.
4. **square-terminal PR D — locations, discovery, and pairing:** Tasks 7–8.
5. **square-terminal PR E — immutable attempt identity and relay:** Tasks 9–10.
6. **Release PR:** Task 11 after broker deploy and hosted evidence.

Each PR must target the repository's current main branch and name the companion PRs in its body.

---

### Task 1: Broker configuration, protocol types, cryptography, and callback policy

**Repository:** wcpos/wcpos-com

**Files:**
- Modify: src/utils/env.ts
- Modify: src/lib/logger.ts
- Create: src/lib/square-broker/types.ts
- Create: src/lib/square-broker/config.ts
- Create: src/lib/square-broker/crypto.ts
- Create: src/lib/square-broker/origin-policy.ts
- Create: src/lib/square-broker/crypto.test.ts
- Create: src/lib/square-broker/origin-policy.test.ts

**Interfaces:**
- Produces: SquareEnvironment, BrokerEnvelope, ConnectionClaims, ConnectionRecord, SafeMerchant, SafeLocation, OAuthHandshake, ExchangeTicket, broker-internal SquareTokenPackage/RefreshTokenVaultRecord, and RelayKeySet.
- Produces: squareConfig(environment), sealJson(value), openJson(value), signConnectionCredential(claims), verifyConnectionCredential(value).
- Produces: validateWordPressCallback(url, environment, resolveDns).
- Consumes later: every broker route and store in Tasks 2–4.

- [ ] **Step 1: Add failing configuration and cryptography tests**

Test exact environment isolation, active/previous signing keys, authenticated encryption tamper rejection, expired credentials, wrong audience, wrong origin, and wrong generation.

~~~ts
it('rejects a credential signed for a different origin', () => {
  const token = signConnectionCredential({
    connectionId: 'AAAAAAAAAAAAAAAAAAAAAA',
    generation: 1,
    merchantId: 'merchant_1',
    environment: 'sandbox',
    origin: 'https://shop.example',
    aud: 'wcpos-square-broker-v1',
    iat: 1_700_000_000,
    exp: 1_700_604_800,
  })

  expect(() =>
    verifyConnectionCredential(token, {
      now: 1_700_000_100,
      origin: 'https://other.example',
    }),
  ).toThrowError('connection_origin_mismatch')
})
~~~

Test callback rejection for credentials, fragments, non-HTTPS production URLs, IP literals, nonstandard production ports, and any DNS set containing private/reserved IPv4 or IPv6.

~~~ts
it('rejects mixed public and private DNS answers', async () => {
  await expect(
    validateWordPressCallback(
      'https://shop.example/wp-json/sqtwc/v1/oauth/callback',
      'production',
      async () => ['203.0.113.8', '127.0.0.1'],
    ),
  ).rejects.toThrowError('callback_not_public')
})
~~~

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

~~~bash
pnpm test:unit -- src/lib/square-broker/crypto.test.ts src/lib/square-broker/origin-policy.test.ts
~~~

Expected: FAIL because the square-broker modules and new environment fields do not exist.

- [ ] **Step 3: Define the exact broker protocol and internal secret types**

Implement these shapes in types.ts. `SquareTokenPackage` and
`RefreshTokenVaultRecord` are broker-internal and must never be accepted as a
route response DTO:

~~~ts
export type SquareEnvironment = 'sandbox' | 'production'
export type ConnectionStatus =
  | 'active'
  | 'refreshing'
  | 'disconnecting'
  | 'reconnect_required'
  | 'revoked'

export interface BrokerError {
  ok: false
  code: string
  message: string
  retryable: boolean
  requestId: string
}

export interface BrokerSuccess<T> {
  ok: true
  data: T
  requestId: string
}

export type BrokerEnvelope<T> = BrokerSuccess<T> | BrokerError

export interface ConnectionClaims {
  connectionId: string
  generation: number
  merchantId: string
  environment: SquareEnvironment
  origin: string
  aud: 'wcpos-square-broker-v1'
  iat: number
  exp: number
  kid?: string
}

export interface ConnectionRecord {
  connectionId: string
  generation: number
  merchantId: string
  environment: SquareEnvironment
  origin: string
  status: ConnectionStatus
  operationId: string | null
  fence: number
  providerAuthorizedAt: number
  createdAt: number
  revokedAt: number | null
  lastSeen: number
}

export interface SafeMerchant {
  id: string
  businessName: string
  country: string
}

export interface SafeLocation {
  id: string
  name: string
  status: 'ACTIVE' | 'INACTIVE'
  country: string
  currency: string
  timezone: string
}

export interface SquareTokenPackage {
  accessToken: string
  refreshToken: string
  expiresAt: number
  refreshTokenExpiresAt: number
  shortLived: true
  merchantId: string
  scopes: string[]
}

export interface RefreshTokenVaultRecord {
  connectionId: string
  vaultGeneration: number
  sealedRefreshToken: string
  refreshTokenExpiresAt: number
}

export interface OAuthHandshake {
  environment: SquareEnvironment
  callbackUrl: string
  origin: string
  clientState: string
  verifierChallenge: string
  brokerState: string
  createdAt: number
}

export interface ExchangeTicket {
  ticket: string
  verifierChallenge: string
  authorizationCode: string
  callbackUrl: string
  origin: string
  environment: SquareEnvironment
}

export interface PendingExchange {
  ticket: string
  operationId: string
  phase: 'exchanging' | 'ready' | 'acknowledged' | 'indeterminate'
  fence: number
  sealedResponse: string | null
  expiresAt: number
}

export interface RelayKey {
  id: string
  secret: string
  acceptUntil: number | null
}

export interface RelayKeySet {
  active: RelayKey
  previous: RelayKey | null
}
~~~

- [ ] **Step 4: Add and validate production broker environment variables**

Add Zod fields for:

~~~text
SQUARE_PRODUCTION_APPLICATION_ID
SQUARE_PRODUCTION_APPLICATION_SECRET
SQUARE_PRODUCTION_WEBHOOK_SIGNATURE_KEY
SQUARE_SANDBOX_APPLICATION_ID
SQUARE_SANDBOX_APPLICATION_SECRET
SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY
SQUARE_BROKER_ENCRYPTION_KEYS_JSON
SQUARE_BROKER_SIGNING_KEYS_JSON
SQUARE_RELAY_HKDF_KEYS_JSON
~~~

The three key-ring values are JSON objects with activeKid and a non-empty keys map. Add all nine variables to REQUIRED_ON_PRODUCTION. config.ts must return an environment-specific application ID, secret, OAuth/API base URL, webhook URL, signature key, and isolated Redis prefix without exposing the other environment.

- [ ] **Step 5: Implement standard-library crypto**

Use AES-256-GCM for sealed Redis payloads, HMAC-SHA256 for compact signed connection credentials, constant-time comparisons, explicit audience/expiry checks, and active/previous key lookup by kid. Do not implement a general JWT abstraction.

~~~ts
export function sealJson<T>(value: T): string
export function openJson<T>(value: string): T
export function signConnectionCredential(claims: ConnectionClaims): string
export function verifyConnectionCredential(
  value: string,
  expected: { now: number; origin?: string },
): ConnectionClaims
~~~

- [ ] **Step 6: Implement callback URL validation**

Normalize the origin, require the exact REST path /wp-json/sqtwc/v1/oauth/callback, resolve all A/AAAA answers, reject the entire destination if any answer is non-public, reject redirects and URL credentials, and permit localhost only when the broker is not production.

~~~ts
export async function validateWordPressCallback(
  value: string,
  environment: SquareEnvironment,
  resolveDns: (hostname: string) => Promise<string[]>,
): Promise<{ callbackUrl: string; origin: string; addresses: string[] }>
~~~

- [ ] **Step 7: Add a redacted Square broker logger**

Export squareBrokerLogger from src/lib/logger.ts. Add a sanitizer in the square-broker module that permits request ID, stable event code, connection ID, environment, hashed merchant ID, status class, and retryability only. Tests must assert high-entropy values and keys containing token, code, verifier, secret, signature, or body are removed.

~~~ts
export interface SafeBrokerLog {
  requestId: string
  event: string
  connectionId?: string
  environment?: SquareEnvironment
  merchantHash?: string
  statusClass?: string
  retryable?: boolean
}

export function safeBrokerLog(input: Record<string, unknown>): SafeBrokerLog
~~~

- [ ] **Step 8: Run tests, lint, and type-check**

Run:

~~~bash
pnpm test:unit -- src/lib/square-broker/crypto.test.ts src/lib/square-broker/origin-policy.test.ts
pnpm lint
pnpm type-check
~~~

Expected: all commands PASS.

- [ ] **Step 9: Commit**

~~~bash
git add src/utils/env.ts src/lib/logger.ts src/lib/square-broker
git commit -m "feat(square): add broker protocol security primitives"
~~~

---

### Task 2: Durable authorization store and OAuth session/exchange flow

**Repository:** wcpos/wcpos-com

**Files:**
- Create: src/lib/square-broker/store.ts
- Create: src/lib/square-broker/store.test.ts
- Create: src/lib/square-broker/square-client.ts
- Create: src/lib/square-broker/square-client.test.ts
- Create: src/lib/square-broker/oauth-service.ts
- Create: src/lib/square-broker/oauth-service.test.ts
- Create: src/app/api/integrations/square/v1/capabilities/route.ts
- Create: src/app/api/integrations/square/v1/capabilities/route.test.ts
- Create: src/app/api/integrations/square/v1/sessions/route.ts
- Create: src/app/api/integrations/square/v1/sessions/route.test.ts
- Create: src/app/api/integrations/square/v1/callback/route.ts
- Create: src/app/api/integrations/square/v1/callback/route.test.ts
- Create: src/app/api/integrations/square/v1/exchange/route.ts
- Create: src/app/api/integrations/square/v1/exchange/route.test.ts
- Create: src/app/api/integrations/square/v1/exchange/ack/route.ts
- Create: src/app/api/integrations/square/v1/exchange/ack/route.test.ts

**Interfaces:**
- Consumes: Task 1 protocol, crypto, config, callback policy, logger.
- Produces: SquareBrokerStore with atomic handshake, ticket, connection, encrypted refresh-vault, refresh-operation, disconnect-operation, and route methods.
- Produces: SquareOAuthService.createSession, consumeCallback, exchangeTicket, acknowledgeExchange.
- Produces: GET capabilities, POST sessions, GET callback, POST exchange, POST exchange/ack.

- [ ] **Step 1: Write failing atomic-store tests**

Inject a RedisPort rather than mocking the Upstash package throughout route tests:

~~~ts
export interface RedisPort {
  get<T>(key: string): Promise<T | null>
  set(key: string, value: unknown, options?: { ex?: number; nx?: boolean }): Promise<'OK' | null>
  del(key: string): Promise<number>
  eval<T>(script: string, keys: string[], args: string[]): Promise<T>
}
~~~

Tests must prove:

- broker state is consumed once;
- ticket begins one exchange operation only when verifier proof and idempotency key match;
- a committed pending-exchange result replays byte-for-byte until acknowledgement;
- connection creation is NX and generation starts at 1;
- connection creation reads the current merchant/environment revocation watermark,
  rejects `providerAuthorizedAt` at or below it, and creates the authorization
  and encrypted refresh-vault records in one Lua operation;
- authorization and encrypted refresh-vault records have no TTL;
- exchange publication is impossible until the connection and encrypted vault
  entry are both durable;
- merchant/environment revocation watermark updates are monotonic by signed event time;
- missing Redis fails closed;
- sandbox keys can never read production records.

- [ ] **Step 2: Run the store test and verify RED**

~~~bash
pnpm test:unit -- src/lib/square-broker/store.test.ts
~~~

Expected: FAIL because SquareBrokerStore does not exist.

- [ ] **Step 3: Implement SquareBrokerStore with Lua compare-and-set operations**

Use encrypted values for ten-minute handshakes, five-minute tickets, pending exchanges, the durable per-connection refresh-token vault, and token-bearing operation records. Store authorization records and merchant/environment revocation watermarks unencrypted only when fields are non-secret. All state transitions use Lua scripts and exact connection generation, vault generation, status, fence, and watermark checks.

~~~ts
export interface SquareBrokerStore {
  createHandshake(value: OAuthHandshake): Promise<void>
  consumeHandshake(state: string): Promise<OAuthHandshake | null>
  createTicket(value: ExchangeTicket): Promise<void>
  beginExchange(ticket: string, verifierHash: string, operationId: string): Promise<ExchangeTicket | PendingExchange | null>
  acknowledgeExchange(ticket: string, operationId: string, generation: number): Promise<boolean>
  createConnectionIfAuthorized(record: ConnectionRecord, vault: RefreshTokenVaultRecord): Promise<boolean>
  getConnection(id: string): Promise<ConnectionRecord | null>
  getRefreshVault(id: string): Promise<RefreshTokenVaultRecord | null>
  compareAndSetConnection(expected: ConnectionRecord, next: ConnectionRecord): Promise<boolean>
  beginDisconnect(expected: ConnectionRecord, expectedVaultGeneration: number, operationId: string, fence: number, sealedAccessToken: string): Promise<boolean>
  getRevokedBefore(environment: SquareEnvironment, merchantId: string): Promise<number>
  advanceRevokedBefore(environment: SquareEnvironment, merchantId: string, eventTime: number): Promise<number>
}
~~~

- [ ] **Step 4: Write failing Square client and OAuth service tests**

Mock fetch and cover the fixed permission list, production session=false, PKCE challenge/verifier, short_lived=true on ObtainToken requests, validation that the response says short_lived=true, rotating refresh-token expiry, Square code exchange without sending the app secret, granted-scope equality, merchant retrieval, active-location pagination, safe Square error mapping, and no raw response logging.

~~~ts
expect(authorizeUrl.searchParams.get('scope')).toBe(
  'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE DEVICES_READ DEVICE_CREDENTIAL_MANAGEMENT',
)
expect(authorizeUrl.searchParams.get('session')).toBe('false')
expect(authorizeUrl.searchParams.get('code_challenge_method')).toBe('S256')
~~~

- [ ] **Step 5: Implement the narrow Square REST client**

Use existing global fetch, explicit Square-Version, eight-second AbortSignal timeouts, response-size limits, and environment base URLs. Expose only:

~~~ts
exchangeAuthorizationCode(input): Promise<SquareTokenPackage>
refreshToken(input): Promise<SquareTokenPackage>
revokeToken(input: { accessToken: string; revokeOnlyAccessToken: true }): Promise<void>
getMerchant(accessToken): Promise<SafeMerchant>
listLocations(accessToken): Promise<SafeLocation[]>
verifyWebhookSignature(rawBody, signature, environment): boolean
~~~

- [ ] **Step 6: Implement OAuth session, callback, and exchange service**

Session input is a strict Zod object containing environment, callbackUrl, clientState, and verifierChallenge. The caller cannot supply scopes. Callback atomically consumes state and seals the authorization code without exchanging it. Exchange verifies the PKCE verifier, creates a pending operation under the client idempotency key, calls Square with short_lived=true, requires the response's short_lived field to be true, derives providerAuthorizedAt from expiresAt minus exactly 86,400 seconds, creates a random 128-bit base64url connection ID, then calls `createConnectionIfAuthorized`. That single Lua operation reads the current Square-event revocation watermark, requires providerAuthorizedAt to be strictly greater, and atomically creates the connection and generation-one encrypted refresh vault without using broker wall-clock time. Exchange then stores a replayable encrypted result that excludes the refresh token and returns only:

~~~ts
export interface ExchangeResponse {
  connectionId: string
  generation: 1
  connectionCredential: string
  environment: SquareEnvironment
  merchant: SafeMerchant
  locations: SafeLocation[]
  accessToken: string
  expiresAt: number
  relayKeys: RelayKeySet
}
~~~

Assert the serialized response has no `refreshToken` or `refreshTokenExpiresAt` key. If Square returns but the atomic create rejects the post-token fence/watermark check, delete any vault entry, revoke the returned access token, and do not create a connection. An equal watermark is revoked; malformed short-lived metadata fails closed. Tests race a watermark advance between the token response and connection creation and prove the atomic create rejects it. Tests also set the broker clock both ahead of and behind Square and prove the derived provider authorization time is unchanged. If the process dies in the exact response-before-persistence window, mark the ticket indeterminate on recovery and require a new authorization; never call Square again with that code. POST exchange/ack deletes replay state only after WordPress confirms the matching generation is durable.

- [ ] **Step 7: Implement and test the five route handlers**

Every route validates content type and body size, uses a stable BrokerEnvelope, creates a request ID, applies per-IP and per-origin rate limits, and returns 503 when Redis/rate limiting is unavailable for a mutation. capabilities returns protocolVersion: 1 plus sandbox/production availability booleans and no secret-derived values.

~~~ts
export async function GET(): Promise<NextResponse<BrokerEnvelope<CapabilitiesResponse>>>
export async function POST(request: NextRequest): Promise<NextResponse>

export interface CapabilitiesResponse {
  protocolVersion: 1
  sandbox: boolean
  production: boolean
}
~~~

- [ ] **Step 8: Run focused and full broker validation**

~~~bash
pnpm test:unit -- src/lib/square-broker src/app/api/integrations/square/v1
pnpm lint
pnpm type-check
pnpm build
~~~

Expected: all commands PASS.

- [ ] **Step 9: Commit**

~~~bash
git add src/lib/square-broker src/app/api/integrations/square/v1
git commit -m "feat(square): add OAuth session and exchange broker"
~~~

---

### Task 3: Two-phase refresh, acknowledgement, heartbeat, and multi-site-safe disconnect

**Repository:** wcpos/wcpos-com

**Files:**
- Modify: src/lib/square-broker/types.ts
- Modify: src/lib/square-broker/store.ts
- Modify: src/lib/square-broker/store.test.ts
- Modify: src/lib/square-broker/oauth-service.ts
- Modify: src/lib/square-broker/oauth-service.test.ts
- Create: src/app/api/integrations/square/v1/refresh/route.ts
- Create: src/app/api/integrations/square/v1/refresh/route.test.ts
- Create: src/app/api/integrations/square/v1/refresh/ack/route.ts
- Create: src/app/api/integrations/square/v1/refresh/ack/route.test.ts
- Create: src/app/api/integrations/square/v1/heartbeat/route.ts
- Create: src/app/api/integrations/square/v1/heartbeat/route.test.ts
- Create: src/app/api/integrations/square/v1/disconnect/route.ts
- Create: src/app/api/integrations/square/v1/disconnect/route.test.ts
- Create: src/app/api/integrations/square/v1/maintenance/route.ts
- Create: src/app/api/integrations/square/v1/maintenance/route.test.ts
- Create: src/lib/square-broker/fail-closed-restore.ts
- Create: src/lib/square-broker/fail-closed-restore.test.ts
- Create: scripts/square-broker-fail-closed-restore.ts
- Modify: package.json
- Modify: vercel.json

**Interfaces:**
- Consumes: Task 2 SquareBrokerStore and Square client.
- Produces: beginRefresh, replayRefresh, acknowledgeRefresh, beginDisconnect, retryDisconnect, heartbeat, and sanitizeRestoredAuthorizationState.
- Preserves: at most one distributed live access token per connection; an undistributed crash-window token is bounded to 24 hours and forces reconnect.

- [ ] **Step 1: Write the adversarial state-machine tests first**

Use deferred promises around the Square client and assert:

1. refreshing plus a monotonic fence is persisted before Square is called.
2. PKCE refresh reads the single-use refresh token only from the encrypted broker vault, sends short_lived=true, validates short_lived=true in the response, and atomically rotates the vault generation.
3. Crash injection immediately after ObtainToken returns/before persistence never calls Square a second time and marks reconnect_required.
4. A refresh result is persisted before the old access token is revoked.
5. The new token is not returned until old-token revocation succeeds.
6. A lost response replays byte-for-byte under the same idempotency key.
7. Acknowledgement deletes replay state only after generation matches.
8. Missing replay state fails closed instead of refreshing again.
9. Lease expiry plus a newer fence prevents the stale worker from committing; a returned token is revoked.
10. Site A disconnect does not affect Site B for the same merchant.
11. Only the operation key that changed active to disconnecting can retry.
12. A refresh that returns after disconnect starts has its new token revoked.
13. Disconnect reaches revoked only after all known token cleanup succeeds.
14. A merchant revocation watermark racing exchange prevents the raced connection becoming active, while a delayed older event does not revoke later reauthorization.
15. Exchange/refresh JSON and WordPress-shaped fixtures contain no refresh token; a refresh request containing `refreshToken` is rejected.
16. Broker wall clocks ahead of and behind Square produce the same providerAuthorizedAt, an exact watermark tie fails closed, and refresh never advances the original value.
17. Disconnect atomically deletes the vault entry before remote access-token revocation; after that transition neither broker refresh nor a WordPress-held secret can call Square ObtainToken.
18. A snapshot taken before disconnect and restored afterward is sanitized before capabilities are enabled: refresh is rejected, every restored vault/operation secret is purged, and Square ObtainToken is never called.

~~~ts
expect(siteA.status).toBe('revoked')
expect(siteB.status).toBe('active')
await expect(service.refresh(siteARequest)).rejects.toMatchObject({
  code: 'connection_revoked',
})
await expect(service.refresh(siteBRequest)).resolves.toMatchObject({
  generation: 2,
})
~~~

- [ ] **Step 2: Run tests and verify RED**

~~~bash
pnpm test:unit -- src/lib/square-broker/oauth-service.test.ts src/app/api/integrations/square/v1/refresh
~~~

Expected: FAIL on absent operation transitions and routes.

- [ ] **Step 3: Implement refresh operation persistence**

The encrypted operation record is:

~~~ts
export interface RefreshResponse {
  connectionId: string
  inputGeneration: number
  outputGeneration: number
  operationId: string
  accessToken: string
  expiresAt: number
  connectionCredential: string
  relayKeys: RelayKeySet
}

export interface RefreshOperation {
  connectionId: string
  operationId: string
  inputGeneration: number
  outputGeneration: number
  inputVaultGeneration: number
  fence: number
  phase: 'prepared' | 'provider_started' | 'revoke_old' | 'ready' | 'acknowledged' | 'indeterminate'
  oldAccessToken: string
  response: RefreshResponse | null
  expiresAt: number
}
~~~

Acquire a monotonic fence when taking the per-connection Redis lease. Read and decrypt the refresh token only from the durable vault. Persist `prepared`, then persist `provider_started`, before the provider call; recovery may resume only `prepared`, while `provider_started` is indeterminate and must never call Square again. Every post-Square Lua commit validates connection status, input connection generation, input vault generation, operation ID, and fence. The successful commit atomically replaces the encrypted vault entry with Square's rotated refresh token and persists an encrypted replay response containing only the access token. Persist that response before revoking oldAccessToken. Return only in ready. Retain ready until POST refresh/ack proves operation ID and output generation, or until the returned access token expires. If the result is indeterminate, delete the live vault entry, move to reconnect_required, and revoke all known access tokens. Preserve `providerAuthorizedAt` exactly across every refresh. Do not describe Redis deletion as cryptographic erasure because encrypted copies can remain in protected backups.

- [ ] **Step 4: Implement disconnecting state**

~~~ts
export interface DisconnectOperation {
  connectionId: string
  operationId: string
  generation: number
  fence: number
  phase: 'revoke_current' | 'drain_refresh' | 'complete'
  currentAccessToken: string
  createdAt: number
}
~~~

In one fenced Lua operation, CAS active to disconnecting with operationId, copy the request's current access token into the encrypted bounded operation record, and delete the durable refresh-vault entry. Same-key retries resume; different keys fail with connection_busy. Revoke current access token with revoke_only_access_token=true, revoke any access token from a refresh that crossed the transition, delete route cache, erase encrypted operation secrets, then finalize revoked. The vault deletion occurs before any fallible provider call and is idempotent on the same operation ID.

Every disconnect post-Square transition validates its fence. A stale owner can revoke a token it received but cannot publish state. The current fence owner drains earlier operations before finalizing.

- [ ] **Step 5: Implement heartbeat**

Heartbeat verifies active status, origin, merchant, environment, and generation; updates lastSeen and the 35-day route cache; and returns active/previous relay keys with exact previous-key expiry. A revoked or missing record fails closed.

~~~ts
export interface HeartbeatResponse {
  connectionId: string
  generation: number
  status: 'active'
  relayKeys: RelayKeySet
  routeExpiresAt: number
}
~~~

- [ ] **Step 6: Implement strict route handlers and tests**

The WordPress server posts its connection credential, connection ID, generation, operation ID, and current access token only. The strict schema rejects unknown fields so `refreshToken` cannot be smuggled into a request. Rate limit and Redis outages return retryable 503. Already-acknowledged refresh and already-completed same-key disconnect return idempotent success.

~~~ts
const RefreshRequest = z.object({
  connectionCredential: z.string().min(1).max(4096),
  connectionId: z.string().regex(/^[A-Za-z0-9_-]{22}$/),
  generation: z.number().int().positive(),
  operationId: z.string().uuid(),
  accessToken: z.string().min(1).max(4096),
}).strict()
~~~

- [ ] **Step 7: Implement broker operation maintenance**

Maintain a Redis sorted set of pending exchange/refresh/disconnect operations by next cleanup time. Add a CRON_SECRET-protected POST maintenance route and a daily Vercel cron. In bounded batches, tombstone abandoned pending exchanges, revoke every known access token, resume only pre-provider `prepared` refreshes, mark stale `provider_started` operations reconnect_required while deleting their vault entries, and resume same-key disconnect cleanup. Removal from the sorted set happens only after cleanup is durable.

Implement the separately invoked fail-closed restore sanitizer. It runs only
while OAuth capabilities are administratively disabled, scans every restored
connection, atomically changes each non-revoked status to
`reconnect_required`, and deletes its vault, exchange/refresh/disconnect
operation, and relay-route keys. A separate environment-prefix sweep deletes
restored handshakes, tickets, pending exchanges, operation indexes, and any
orphan vault/operation/route keys that have no registry record. It is
cursor-resumable and idempotent; a checkpoint is complete only after final
zero-result scans of both the registry and every secret-bearing prefix. The CLI exits
nonzero on any incomplete batch, and the runbook forbids re-enabling
capabilities without its completed marker. It never decrypts or calls Square
with a restored token.

~~~ts
export async function sanitizeRestoredAuthorizationState(
  store: SquareBrokerStore,
  environment: SquareEnvironment,
  cursor: string,
  batchSize: number,
): Promise<{ nextCursor: string; examined: number; sanitized: number; complete: boolean }>
~~~

Add `square:restore-fail-closed` to package.json and test the exact sequence:
create active connection/vault snapshot, disconnect and delete the live vault,
load the old snapshot into a fresh RedisPort, sanitize twice, then assert
`reconnect_required`, no vault/operation/route keys, refresh rejection, and zero
Square ObtainToken calls.

~~~ts
export interface MaintenanceResult {
  examined: number
  cleaned: number
  reconnectRequired: number
  retryableFailures: number
}
~~~

- [ ] **Step 8: Run the full broker core validation**

~~~bash
pnpm test:unit -- src/lib/square-broker src/app/api/integrations/square/v1
pnpm lint
pnpm type-check
pnpm build
~~~

Expected: all commands PASS.

- [ ] **Step 9: Commit and open wcpos-com PR A**

~~~bash
git add src/lib/square-broker src/app/api/integrations/square/v1 vercel.json
git commit -m "feat(square): add safe token lifecycle operations"
git push -u origin codex/square-oauth-broker
gh pr create --base main --title "Add Square OAuth broker authorization core" --body-file /tmp/square-broker-pr.md
~~~

The PR body must state that the durable Redis registry is security authority, registry loss fails closed, and Connect remains hidden until deployment.

---

### Task 4: Square webhook verification and pinned WordPress relay

**Repository:** wcpos/wcpos-com

**Files:**
- Create: src/lib/square-broker/relay-signature.ts
- Create: src/lib/square-broker/relay-signature.test.ts
- Create: src/lib/square-broker/pinned-https.ts
- Create: src/lib/square-broker/pinned-https.test.ts
- Create: src/lib/square-broker/webhook-service.ts
- Create: src/lib/square-broker/webhook-service.test.ts
- Create: src/app/api/integrations/square/v1/webhooks/[environment]/route.ts
- Create: src/app/api/integrations/square/v1/webhooks/[environment]/route.test.ts

**Interfaces:**
- Consumes: active durable authorization record and cache route from Tasks 2–3.
- Produces: deriveRelayKey(connectionId, keyId), signRelayRequest(input), pinnedHttpsPost(input), handleSquareWebhook(input).
- Produces: Square application webhook route for sandbox and production.

- [ ] **Step 1: Write failing signature, SSRF, and routing tests**

Cover exact Square signature URL/raw-body verification, malformed reference IDs, merchant/environment mismatch, route-cache eviction, revoked/unknown connections, event deduplication, relay key overlap, site 2xx/4xx/429/5xx mapping, redirect rejection, proxy-env bypass, private IPv4/IPv6, and DNS rebinding. Route tests must also prove that a verified `oauth.authorization.revoked` event calls `advanceRevokedBefore` with its environment, merchant ID, and event creation time before returning 200, while a failed watermark update returns 503.

~~~ts
it('pins the validated address even if DNS changes before connect', async () => {
  const resolver = vi.fn()
    .mockResolvedValueOnce(['198.51.100.20'])
    .mockResolvedValueOnce(['127.0.0.1'])

  await pinnedHttpsPost({
    url: 'https://shop.example/wp-json/sqtwc/v1/relay',
    resolver,
    body: '{}',
    headers: {},
  })

  expect(mockHttpsRequest).toHaveBeenCalledWith(
    expect.objectContaining({
      hostname: '198.51.100.20',
      servername: 'shop.example',
      headers: expect.objectContaining({ host: 'shop.example' }),
    }),
    expect.any(Function),
  )
})
~~~

- [ ] **Step 2: Run tests and verify RED**

~~~bash
pnpm test:unit -- src/lib/square-broker/relay-signature.test.ts src/lib/square-broker/pinned-https.test.ts src/lib/square-broker/webhook-service.test.ts
~~~

Expected: FAIL because relay modules do not exist.

- [ ] **Step 3: Implement reference parsing and relay key rotation**

Accept only sq_ plus a 22-character base64url routing ID plus underscore plus a 1–13 character lowercase base36 order ID. Decode to a positive 64-bit-safe decimal string without JavaScript Number truncation. Derive per-site HMAC keys with versioned HKDF. Sign timestamp, connection ID, callback path, event ID, relay key ID, and raw body. Return active plus bounded previous keys through exchange/refresh/heartbeat.

~~~ts
export function parseCheckoutReference(value: string): {
  connectionId: string
  orderId: bigint
} | null

export function signRelayRequest(input: {
  key: RelayKey
  timestamp: number
  connectionId: string
  callbackPath: string
  eventId: string
  rawBody: string
}): string
~~~

- [ ] **Step 4: Implement direct pinned HTTPS delivery**

Use node:https.request, not global fetch. Resolve all A/AAAA records once per attempt; reject if any is non-public; select one public address; connect to that address; preserve Host; set servername to the canonical hostname; call tls.checkServerIdentity against the canonical hostname; disable redirects; ignore HTTP_PROXY/HTTPS_PROXY; cap request/response size; and set short connect/total timeouts. Every retry performs fresh resolve-and-pin.

~~~ts
export interface PinnedHttpsResponse {
  status: number
  body: Buffer
}

export function pinnedHttpsPost(input: {
  url: string
  headers: Record<string, string>
  body: Buffer
  timeoutMs: number
  maxResponseBytes: number
}): Promise<PinnedHttpsResponse>
~~~

- [ ] **Step 5: Implement webhook service response rules**

- Invalid Square signature: 401.
- Verified duplicate: 200.
- Unknown or revoked connection: safe log and 202.
- Active authorization with missing route cache: 503 so Square retries.
- Destination 2xx: 200.
- Permanent destination 4xx except 429: acknowledge 202 after safe log.
- DNS/network/timeout/429/5xx: 503.

Only terminal.checkout.updated is relayed in the first release. `handleSquareWebhook` dispatches a verified `oauth.authorization.revoked` event to `advanceRevokedBefore` with its environment, merchant ID, and event creation time before acknowledging it. The store advances the merchant/environment revoked-before watermark atomically; every connection authorized at or before that watermark fails closed. If the store operation fails, return 503 so Square retries.

~~~ts
export function relayOutcomeStatus(outcome: RelayOutcome): number {
  if (outcome === 'duplicate' || outcome === 'delivered') return 200
  if (outcome === 'unknown' || outcome === 'revoked' || outcome === 'permanent_4xx') return 202
  return 503
}
~~~

- [ ] **Step 6: Run full validation and commit PR B**

~~~bash
pnpm test:unit -- src/lib/square-broker src/app/api/integrations/square/v1
pnpm lint
pnpm type-check
pnpm build
git diff --check
git add src/lib/square-broker src/app/api/integrations/square/v1
git commit -m "feat(square): relay Terminal webhooks safely"
~~~

Open a companion PR that targets main after PR A is merged, or targets PR A's branch only while explicitly marked as stacked. Do not deploy the relay before PR A's registry is live.

---

### Task 5: Encrypted immutable connection store and legacy manual migration

**Repository:** wcpos/square-terminal-for-woocommerce

**Files:**
- Create: includes/Connections/SecretBox.php
- Create: includes/Connections/ConnectionStore.php
- Create: includes/Connections/ConnectionTransitionStore.php
- Create: includes/Connections/ConnectionLock.php
- Create: includes/Connections/ConnectionService.php
- Create: includes/Connections/ConnectionMigrator.php
- Create: tests/includes/SecretBoxTest.php
- Create: tests/includes/ConnectionStoreTest.php
- Create: tests/includes/ConnectionTransitionStoreTest.php
- Create: tests/includes/ConnectionLockTest.php
- Create: tests/includes/ConnectionServiceTest.php
- Create: tests/includes/ConnectionMigratorTest.php
- Modify: includes/Settings.php
- Modify: includes/Services/SquareClientFactory.php
- Modify: tests/stubs/wordpress.php

**Interfaces:**
- Produces: SecretBox.encrypt/decrypt; ConnectionStore record CAS and active pointers; ConnectionTransitionStore durable phase journal/recovery inputs; ConnectionLock.with_lock; ConnectionService.active/resolve; ConnectionMigrator.migrate.
- Consumes later: admin OAuth, devices, payment lifecycle, sweeper, and webhooks.

- [ ] **Step 1: Extend WordPress stubs and write failing encryption tests**

Add deterministic stubs for home_url, wp_parse_url, wp_remote_request, transients, wp_rand, wp_generate_password, and option compare/update behavior. Test AES-256-GCM round-trip, random nonce, tamper rejection, salt/origin change failure, and OpenSSL-unavailable fail closed.

~~~php
$box = new SecretBox('https://shop.example');
$ciphertext = $box->encrypt('square-secret');

self::assertNotSame('square-secret', $ciphertext);
self::assertSame('square-secret', $box->decrypt($ciphertext));
self::assertNull($box->try_decrypt(substr($ciphertext, 0, -1) . 'x'));
~~~

- [ ] **Step 2: Run the focused test and verify RED**

~~~bash
composer test -- --filter SecretBoxTest
~~~

Expected: FAIL because SecretBox does not exist.

- [ ] **Step 3: Implement authenticated local encryption**

Derive a 32-byte key with HKDF-SHA256 from wp_salt('auth'), normalized site origin, and context square-terminal-connections-v1. Store version, nonce, tag, and ciphertext in base64url JSON. Never localize or render decrypted values.

~~~php
final class SecretBox {
    public function __construct(string $site_origin) {}
    public function available(): bool {}
    public function encrypt(string $plaintext): string {}
    public function decrypt(string $encoded): string {}
    public function try_decrypt(string $encoded): ?string {}
}
~~~

- [ ] **Step 4: Write failing per-record atomic-store and mutation-lock tests**

Use one non-autoloaded option per record and one per environment pointer:

~~~php
sqtwc_connection_v1_<sha256-connection-id> = {
    id: connection-id,
    method: manual,
    environment: sandbox,
    local_generation: 1,
    local_fence: 1,
    status: active,
    secret: encrypted-value
}
sqtwc_active_connection_v1_sandbox = {
    id: connection-id,
    generation: 1
}
sqtwc_connection_transition_v1_sandbox = {
    operation_id: uuid,
    fence: 17,
    type: activation|replacement|disconnect|manual_switch,
    phase: prepared,
    source_id: connection-id|null,
    target_id: connection-id|null,
    expected_record: exact-serialized-value|null,
    target_record: exact-serialized-value|null,
    expected_pointer: exact-serialized-value|null,
    target_pointer: exact-serialized-value|null
}
~~~

Tests prove atomic add_option creation, exact serialized-value plus generation/fence conditional SQL update, explicit wp_cache_delete after a successful write, one active pointer per environment, retired records remain resolvable, revoked records cannot create new checkouts, and a stale save cannot overwrite a disconnect or account switch. The transition store creates one intent per environment with atomic add_option, advances each phase by exact-value SQL CAS, recognizes already-applied target values during recovery, and deletes only an exact completed intent. Corrupt or ambiguous state fails closed. Two independent store instances both read generation N; after lease expiry exactly one may write N+1. ConnectionLock increments a monotonic fence, uses an owner token and bounded expiry, and performs compare-before-delete release.

OAuth record fixtures contain encrypted access token, access-token expiry, connection credential, and relay keys, but no refresh-token or refresh-expiry field. Recursively scan every stored option/transient fixture and assert neither a `refreshToken` key nor the mock Square refresh-token value occurs.

- [ ] **Step 5: Implement ConnectionStore and ConnectionService**

Required public API:

~~~php
public function get(string $id): ?array;
public function active(string $environment): ?array;
public function create(array $record): bool;
public function compare_and_swap(string $id, array $expected, array $next): bool;
public function activate(string $environment, array $expected_pointer, array $next_pointer): bool;
public function retire(string $id, array $expected, int $fence): bool;
public function mark_revocation_pending(string $id, array $expected, string $operation_id, int $fence): bool;
public function delete_if_unreferenced(string $id, callable $is_referenced): bool;
~~~

compare_and_swap performs one prepared UPDATE on $wpdb->options with WHERE option_name = expected name AND option_value = exact previously read serialized value, checks affected_rows === 1, and invalidates the options cache. It never implements CAS as get_option followed by update_option. ConnectionService::for_new_checkout returns only an active validated record. ConnectionService::for_identity resolves the exact immutable ID and generation, including retired records, for status/cancel/sweeper work.

ConnectionTransitionStore exposes this exact journal API:

~~~php
public function begin(string $environment, array $intent): bool;
public function get(string $environment): ?array;
public function advance(string $environment, array $expected, array $next): bool;
public function complete(string $environment, array $completed): bool;
public function assert_record_or_target(array $intent, ?array $actual): string;
public function assert_pointer_or_target(array $intent, ?array $actual): string;
~~~

ConnectionService::for_new_checkout and the device/admin mutation entry points first call `get($environment)` and throw `connection_transition_pending` whenever an intent exists. `for_identity` may continue only when the intent's source_id and target_id are both different from the historical record ID. There is no read/check/update_option fallback.

- [ ] **Step 6: Write failing migration and downgrade tests**

Cover:

- production and sandbox manual tokens imported separately;
- the active shared location/webhook copied only to the active environment;
- blank settings submissions preserve saved tokens;
- pre-OAuth upgrade leaves legacy fields intact;
- OAuth activation blanks fields read by v0.2.2;
- code-only downgrade while OAuth active has no usable legacy token;
- explicit validated manual switch repopulates the safe legacy fields;
- migration is idempotent and never overwrites a newer record.

~~~php
public function test_oauth_activation_scrubs_legacy_fields_and_downgrade_fails_closed(): void {
    $this->migrator->migrate();
    $this->migrator->activate_oauth($this->oauth_record);

    $settings = get_option('woocommerce_sqtwc_settings');
    self::assertSame('', $settings['sandbox_access_token']);
    self::assertNotEmpty($this->store->manual_records('sandbox'));
}
~~~

- [ ] **Step 7: Implement ConnectionMigrator and Settings compatibility**

Run migration on plugin init before any Square client creation. Do not assign pre-upgrade attempts to the imported manual record; Task 9 validates each legacy checkout before binding. Keep Settings accessors for v0.2.2-compatible manual mode, but route all new client creation through ConnectionService. SquareClientFactory becomes:

~~~php
public function create(array $connection): SquareClient {
    return new SquareClient(
        $connection['access_token'],
        null,
        array('baseUrl' => $connection['base_url'])
    );
}
~~~

- [ ] **Step 8: Run plugin validation and commit**

~~~bash
composer lint
composer test
composer test:js
git diff --check
git add includes/Connections includes/Settings.php includes/Services/SquareClientFactory.php tests
git commit -m "feat: add immutable Square connection storage"
~~~

---

### Task 6: Broker client, managed OAuth callback, and connection settings UI

**Repository:** wcpos/square-terminal-for-woocommerce

**Files:**
- Create: includes/Connections/BrokerClient.php
- Create: includes/Connections/OAuthController.php
- Create: includes/Connections/ConnectionAdminController.php
- Create: includes/Connections/ConnectionMaintenance.php
- Create: includes/Connections/SquareOperationRunner.php
- Create: tests/includes/BrokerClientTest.php
- Create: tests/includes/OAuthControllerTest.php
- Create: tests/includes/ConnectionAdminControllerTest.php
- Create: tests/includes/ConnectionMaintenanceTest.php
- Create: tests/includes/SquareOperationRunnerTest.php
- Modify: includes/Plugin.php
- Modify: includes/Gateway.php
- Modify: assets/js/admin.js
- Modify: assets/css/admin.css
- Modify: tests/includes/AdminUiTest.php
- Create: tests/js/admin-connection.test.js
- Modify: package.json

**Interfaces:**
- Consumes: Task 5 store/service/transition-journal/lock; broker protocol v1.
- Produces: Connect, callback exchange, pending merchant confirmation, refresh/ack, reconnect, manual switch, disconnect, twice-daily short-token maintenance, and one auth-expired retry.
- Produces: capability-gated admin state rendered from safe connection metadata.

- [ ] **Step 1: Write failing BrokerClient contract tests**

Inject the HTTP transport and assert JSON/content-type/body caps, eight-second timeouts, stable error mapping, no redirects, no secret logging, capabilities version mismatch, lost refresh replay, refresh acknowledgement, same-operation disconnect retry, environment isolation, strict rejection of broker responses containing a refresh token, and that refresh/disconnect requests contain only the current access token plus connection identity.

~~~php
$result = $client->capabilities();
self::assertSame(1, $result['protocolVersion']);
self::assertTrue($result['sandbox']);
self::assertFalse($result['production']);
~~~

- [ ] **Step 2: Run BrokerClient tests and verify RED**

~~~bash
composer test -- --filter BrokerClientTest
~~~

Expected: FAIL because BrokerClient does not exist.

- [ ] **Step 3: Implement BrokerClient**

Use wp_safe_remote_request for broker calls, fixed https://wcpos.com/api/integrations/square/v1 base URL filterable only for non-production tests, strict response envelopes, and redacted exceptions. Implement:

~~~php
capabilities(): array
create_session(array $request): array
exchange(string $ticket, string $verifier, string $operation_id): array
ack_exchange(string $ticket, string $operation_id, int $generation): void
refresh(array $connection, string $operation_id): array
ack_refresh(array $connection, string $operation_id): void
heartbeat(array $connection): array
disconnect(array $connection, string $operation_id): array
~~~

`refresh()` serializes `connectionCredential`, `connectionId`, broker generation, stable operation ID, and the decrypted current access token. It never accepts or emits a refresh token. `exchange()` and `refresh()` reject any success payload containing `refreshToken` or `refreshTokenExpiresAt` as a broker protocol violation before persistence.

- [ ] **Step 4: Write failing OAuth/controller tests**

Test manage_woocommerce and nonce requirements, ten-minute client state/PKCE verifier/exchange-operation transient, fixed callback URL, pending exchange replay after response loss, acknowledgement only after local atomic persistence, pending connection after exchange, explicit Use this Square account confirmation, production session=false broker handoff, exact official WooCommerce Square location hint only, cross-merchant second confirmation, one mutation lock/fence, stale callback/refresh CAS rejection, open-attempt guard, OpenSSL-unavailable managed mode fail-closed with manual mode usable, no WordPress persistence/request/log contains a refresh token, and no remote mutation on page render.

~~~php
public function test_exchange_stays_pending_until_explicit_merchant_confirmation(): void {
    $response = $this->controller->oauth_callback(
        array('ticket' => 'ticket_1', 'client_state' => 'state_1')
    );

    self::assertSame('pending', $this->store->get('connection_1')['status']);
    self::assertNull($this->store->active('sandbox'));
    self::assertSame('https://shop.example/wp-admin/admin.php?page=wc-settings&tab=checkout&section=sqtwc', $response['redirect']);
}
~~~

- [ ] **Step 5: Register precise WordPress endpoints**

Plugin.php registers:

~~~text
wp_ajax_sqtwc_square_connect
wp_ajax_sqtwc_square_confirm
wp_ajax_sqtwc_square_refresh
wp_ajax_sqtwc_square_heartbeat
wp_ajax_sqtwc_square_disconnect
wp_ajax_sqtwc_square_switch_manual
REST GET /sqtwc/v1/oauth/callback
~~~

The callback accepts only ticket and client_state, verifies the administrator-bound transient, exchanges server-to-server using the stored PKCE verifier and stable exchange operation ID, rejects a response containing any refresh-token field, atomically stores the access-token-only connection as pending_ack with the ticket/operation identity, acknowledges the broker exchange, and redirects to the Square settings page without secrets. If persistence or acknowledgement fails, retrying the same callback replays the same broker result and never reuses the Square authorization code. Merchant activation remains disabled until acknowledgement succeeds.

- [ ] **Step 6: Implement and crash-test durable activation and disconnect transitions**

All mutations run inside ConnectionLock and begin a ConnectionTransitionStore intent before the first record, pointer, or broker write. Every response includes connection ID/local generation and is saved only by CAS. OAuth confirmation activates only after merchant/location display and validation. Use explicit phase sequences:

~~~text
activation/replacement/manual_switch:
prepared -> source_retired -> target_active -> pointer_switched -> complete

disconnect:
prepared -> record_revocation_pending -> pointer_removed -> broker_revoked -> local_erased -> complete
~~~

For a phase that does not apply, advance it without a record/pointer write. Before each write, accept only the exact expected value or the exact target value already applied by an earlier crashed worker. After each write, CAS the journal phase. Recovery uses the same operation ID and never invents a new broker request. Delete the journal only at complete. Forced local forget requires explicit confirmation and still completes the durable journal.

Add a data-provider test that throws after intent creation and after every constituent record, pointer, broker-call/result, and phase write for all four transition types. Recreate the controller/store as a new process, run recovery twice, and assert exactly one active pointer matches exactly one active record, the source state is correct, disconnect reuses one broker operation ID, and no broker authorization/revocation work is orphaned. Also assert new checkout, device, and admin mutations fail with `connection_transition_pending`; exact-identity reconciliation proceeds only for an unaffected historical record.

~~~php
return $this->lock->with_lock(
    $environment,
    function (int $fence) use ($connection_id, $expected_generation): array {
        $record = $this->store->get($connection_id);
        if (
            !$record
            || $record['local_generation'] !== $expected_generation
            || $record['local_fence'] >= $fence
        ) {
            throw new ConnectionConflict('stale_connection_generation');
        }
        return $this->activate_validated_record($record, $fence);
    }
);
~~~

- [ ] **Step 7: Implement transition recovery, scheduled credential maintenance, and the Square operation runner**

Register a twice-daily sqtwc_square_connection_maintenance event. Under ConnectionLock, first recover any durable transition intent to completion or fail closed on corrupt/ambiguous state. Then retry any durable pending exchange acknowledgement and move pending_ack to pending only after broker confirmation. Refresh an OAuth record when its 24-hour access token has six hours or less remaining; SquareOperationRunner performs the same check on demand before an operation. Use the stable refresh operation ID until refresh/ack completes, persist only the returned access token and expiry, and alert when maintenance misses the six-hour threshold or enters reconnect_required. SquareOperationRunner receives an already resolved exact connection record, refreshes proactively, invokes the operation with a client, and retries exactly once after SquareErrorMapper classifies an authentication-expired response. Manual records never call the broker.

~~~php
public function run(array $connection, callable $operation) {
    $connection = $this->maintenance->ensure_exchange_acknowledged(
        $connection
    );
    $connection = $this->maintenance->ensure_fresh(
        $connection
    );
    try {
        return $operation($this->clients->create($connection));
    } catch (Throwable $error) {
        if (!$this->errors->is_authentication_expired($error)) {
            throw $error;
        }
        $connection = $this->maintenance->refresh_once($connection);
        return $operation($this->clients->create($connection));
    }
}
~~~

- [ ] **Step 8: Replace placeholder admin JavaScript with a tested state controller**

Export createAdminController(env) for node:test. It must:

- call capability check before enabling Connect;
- disable duplicate submissions;
- show loading/success/retry states;
- display merchant/environment/location without secrets;
- require Use this Square account;
- require a second account-switch confirmation;
- keep Advanced manual fields collapsed;
- never inject remote text with innerHTML.

~~~js
function createAdminController(env) {
  return {
    connect: function () {},
    confirmMerchant: function () {},
    refresh: function () {},
    disconnect: function () {},
    switchManual: function () {},
    destroy: function () {}
  };
}
~~~

- [ ] **Step 9: Render the connection panel and preserve manual mode**

Gateway.php renders Unconfigured, OAuth connected/pending/revocation_pending, and Manual connected states. When protocol v1 is unavailable, hide/disable Connect with a safe message and leave manual mode operational. Official WooCommerce Square integration supplies only environment/location hints through guarded public APIs.

~~~php
public static function render_connection_panel(array $view): string {
    $state = in_array($view['state'], array('unconfigured', 'pending', 'oauth', 'manual', 'revocation_pending'), true)
        ? $view['state']
        : 'unconfigured';
    return self::render_connection_state($state, $view);
}
~~~

- [ ] **Step 10: Run focused and full validation**

~~~bash
composer lint
composer test
composer test:js
git diff --check
~~~

Expected: all commands PASS, including new admin-connection tests.

- [ ] **Step 11: Commit local connection foundation**

~~~bash
git add includes assets tests package.json
git commit -m "feat: add managed Square connection flow"
~~~

Do not expose the Connect control in a release until wcpos.com capabilities version 1 is deployed.

---

### Task 7: Location discovery, Device Code normalization, and pairing services

**Repository:** wcpos/square-terminal-for-woocommerce

**Files:**
- Create: includes/Services/SquareLocationAdapter.php
- Create: includes/Services/TerminalDiscoveryService.php
- Create: includes/Devices/DeviceAdminController.php
- Create: tests/includes/SquareLocationAdapterTest.php
- Modify: includes/Services/SquareDeviceAdapter.php
- Modify: tests/includes/SquareDeviceAdapterTest.php
- Create: tests/includes/TerminalDiscoveryServiceTest.php
- Create: tests/includes/DeviceAdminControllerTest.php
- Modify: includes/Plugin.php
- Modify: assets/js/admin.js
- Modify: tests/js/admin-connection.test.js

**Interfaces:**
- Consumes: active ConnectionService record and SquareOperationRunner.
- Produces: list eligible locations, normalized selectable Terminals, create/poll Device Code, five-minute device cache.
- Produces: admin AJAX actions for location, discovery, pair, and pair status.

- [ ] **Step 1: Write failing location and pagination tests**

Cover all Square cursor pages, active locations only, exact store-currency eligibility, sole-location auto-selection, exact official-plugin location hint, multiple-location required choice, and no-location disabled state.

~~~php
$locations = $adapter->list_locations();
self::assertSame(
    array('loc_gbp'),
    array_column($service->eligible_locations($locations, 'GBP'), 'id')
);
~~~

- [ ] **Step 2: Run focused tests and verify RED**

~~~bash
composer test -- --filter "SquareLocationAdapterTest|TerminalDiscoveryServiceTest"
~~~

Expected: FAIL because the adapter and service do not exist.

- [ ] **Step 3: Implement SquareLocationAdapter**

Follow Square pagination and normalize only id, name, status, country, currency, and timezone. Do not return address/customer fields to JavaScript. Use the connection record's environment and token through SquareClientFactory.

~~~php
final class SquareLocationAdapter {
    public function __construct(object $client) {}
    /** @return array<int,array{id:string,name:string,status:string,country:string,currency:string,timezone:string}> */
    public function list_locations(): array {}
}
~~~

- [ ] **Step 4: Expand SquareDeviceAdapter test-first**

Add typed SDK operations:

~~~php
public function list_device_codes(?string $cursor = null): array;
public function get_device_code(string $id): array;
public function list_devices(?string $cursor = null): array;
public function create_device_code(array $data): array;
~~~

Normalized Device Code fields are id, name, code, device_id, product_type, status, location_id, created_at, updated_at, and expires_at. Normalized monitoring fields are monitoring_id, name, model, manufacturers_id, status, location_id, and updated_at.

- [ ] **Step 5: Write the normalization matrix before implementation**

Tests must prove:

- only PAIRED plus non-empty device_id plus TERMINAL_API plus exact location is selectable;
- both APIs paginate independently;
- duplicate device_id keeps the newest qualifying Device Code;
- expired, unpaired, unknown-status, empty-ID, wrong-product, and wrong-location codes are excluded;
- monitoring-only devices are visible as informational but not selectable;
- manufacturers_id enriches display only and never replaces checkout device_id;
- mismatched serial/manufacturer data does not merge unrelated devices;
- OFFLINE and NEEDS_ATTENTION remain selectable with warnings.

~~~php
yield 'wrong location' => array($paired_code_for_other_location, $monitoring_device, false);
yield 'monitoring only' => array(null, $monitoring_device, false);
yield 'paired offline' => array($paired_code, $offline_monitoring_device, true);
yield 'duplicate device code' => array($newest_paired_code, $monitoring_device, true);
~~~

- [ ] **Step 6: Implement TerminalDiscoveryService**

~~~php
public function discover(array $connection, bool $bypass_cache = false): array;
public function invalidate(array $connection): void;
public function create_pairing_code(array $connection, string $name): array;
public function get_pairing_status(array $connection, string $code_id): array;
~~~

Cache by environment, merchant ID, location ID, and connection ID for five minutes. Cache contains normalized safe fields only. Sandbox returns Square's documented magic device IDs locally and never calls pairing or ListDevices.

- [ ] **Step 7: Implement capability-protected admin actions**

Register:

~~~text
wp_ajax_sqtwc_square_list_locations
wp_ajax_sqtwc_square_select_location
wp_ajax_sqtwc_square_list_devices_admin
wp_ajax_sqtwc_square_create_device_code
wp_ajax_sqtwc_square_get_device_code
~~~

Require manage_woocommerce and action nonces. Selecting a location increments local generation, invalidates saved/default devices, and passes the open-attempt guard. Pair creation uses a fresh UUID idempotency key. Pair status exposes code and exact expiry but no token.

- [ ] **Step 8: Implement chained pairing UI behavior**

The admin controller displays code, expiry, and on-device instructions; polls with chained setTimeout; stops on PAIRED/expired/page teardown; invalidates discovery on PAIRED; reloads and selects the new device; and offers Generate a new code after expiry. Duplicate clicks send one request.

~~~js
function schedulePairingPoll() {
  pairingTimer = env.setTimeout(function () {
    fetchPairingStatus().then(handlePairingStatus, handlePairingError);
  }, pairingPollMs);
}
~~~

- [ ] **Step 9: Run validation and commit**

~~~bash
composer lint
composer test
composer test:js
git diff --check
git add includes assets tests
git commit -m "feat: discover and pair Square Terminals"
~~~

---

### Task 8: Authorized cashier terminal discovery and loading UI

**Repository:** wcpos/square-terminal-for-woocommerce

**Files:**
- Create: includes/Devices/DeviceAjaxHandler.php
- Create: tests/includes/DeviceAjaxHandlerTest.php
- Modify: includes/Plugin.php
- Modify: includes/Gateway.php
- Modify: assets/js/payment.js
- Modify: assets/css/payment.css
- Modify: tests/includes/PaymentAssetsTest.php
- Modify: tests/includes/PaymentFrontendTest.php
- Modify: tests/js/helpers.js
- Create: tests/js/device-discovery.test.js

**Interfaces:**
- Consumes: TerminalDiscoveryService and existing OrderAccess contract.
- Produces: sqtwc_list_terminal_devices cashier action and payment-controller loadDevices/retry behavior.

- [ ] **Step 1: Write failing server authorization tests**

Test order not found, invalid staff nonce, missing order key/payment request token, unrelated visitor, OAuth safe-field minimization, manual insufficient-scope fallback, sandbox local devices, timeout mapping, and cache bypass restricted to administrators.

~~~php
$response = $handler->list_devices(array(
    'order_id' => 99,
    'order_key' => 'order-key',
));

self::assertSame(200, $response['status']);
self::assertSame(
    array('device_id', 'name', 'model', 'status', 'warning', 'location_id', 'updated_at'),
    array_keys($response['devices'][0])
);
~~~

- [ ] **Step 2: Run the focused test and verify RED**

~~~bash
composer test -- --filter DeviceAjaxHandlerTest
~~~

Expected: FAIL because DeviceAjaxHandler and the action do not exist.

- [ ] **Step 3: Implement and register the cashier action**

Register logged-in and nopriv sqtwc_list_terminal_devices actions. Reuse OrderAccess::can_mutate_order and the existing payment nonce rules exactly. Resolve only ConnectionService::for_new_checkout; do not reveal retired connections or hardware to unrelated users.

~~~php
add_action('wp_ajax_sqtwc_list_terminal_devices', array($this, 'ajax_list_terminal_devices'));
add_action('wp_ajax_nopriv_sqtwc_list_terminal_devices', array($this, 'ajax_list_terminal_devices'));
~~~

- [ ] **Step 4: Write failing JavaScript states**

Cover:

- production begins with Loading Square terminals and sends one request;
- single healthy Terminal auto-selects;
- multiple Terminals require explicit selection;
- stored choice restores only for the same location and existing device;
- no devices renders No paired terminals and admin-only setup link;
- retryable failure renders Retry and succeeds later;
- OAuth never renders free text;
- manual insufficient-scope mode retains free text;
- dynamic names/errors use textContent;
- Start stays disabled until a device is selected;
- stale discovery response after environment/location change is ignored.

~~~js
test('a stale device response cannot replace a newer location', async function () {
  var ctx = setupDiscovery({ locationId: 'loc_a' });
  ctx.controller.loadDevices();
  ctx.controller.setLocation('loc_b');
  ctx.fetch.settle({ ok: true, devices: [{ device_id: 'old' }] });
  await flush();
  assert.equal(ctx.root.querySelector('#sqtwc-device-id').children.length, 0);
});
~~~

- [ ] **Step 5: Run JavaScript test and verify RED**

~~~bash
node --test tests/js/device-discovery.test.js
~~~

Expected: FAIL because payment controller has no asynchronous discovery state.

- [ ] **Step 6: Implement discovery in the existing injected controller**

Add a DISCOVERING state without altering the existing create/poll/cancel states. Inject fetch/timers/storage as the controller already does. Use one request generation counter to ignore stale responses. Populate option nodes via createElement and textContent. Persist last choice under a key containing environment plus location ID.

~~~js
function loadDevices() {
  var requestGeneration = ++deviceRequestGeneration;
  setDeviceState('discovering');
  return post(actions.listDevices, paymentAuth()).then(function (response) {
    if (requestGeneration !== deviceRequestGeneration) return;
    renderDevices(response.devices || []);
  });
}
~~~

- [ ] **Step 7: Render static safe placeholders**

Gateway::render_payment_ui renders an empty select, loading/status region, Retry button, and manual fallback container. PHP never injects remote device names. Localized data contains the list action, nonce/access token contract, environment, active method, selected location, strings, and whether the current user may see the setup link.

~~~html
<select id="sqtwc-device-id" class="sqtwc-payment__device-select" disabled></select>
<p class="sqtwc-payment__device-status" role="status">Loading Square terminals…</p>
<button type="button" data-sqtwc-action="retry-devices" hidden>Retry</button>
~~~

- [ ] **Step 8: Run full validation and commit PR D**

~~~bash
composer lint
composer test
composer test:js
git diff --check
git add includes assets tests
git commit -m "feat: load Square Terminals in the cashier"
~~~

Open plugin PR D after the connection foundation PR is reviewable. Its test plan must exercise loading, empty, retry, one-device, multi-device, and manual fallback states.

---

### Task 9: Immutable connection identity for checkout, status, cancel, detach, and sweeper

**Repository:** wcpos/square-terminal-for-woocommerce

**Files:**
- Create: includes/Connections/ConnectionIdentity.php
- Create: includes/Connections/LegacyAttemptBinder.php
- Create: tests/includes/ConnectionIdentityTest.php
- Create: tests/includes/LegacyAttemptBinderTest.php
- Modify: includes/Services/OrderMeta.php
- Modify: tests/includes/OrderMetaTest.php
- Modify: includes/AjaxHandler.php
- Modify: tests/includes/AjaxHandlerTest.php
- Modify: includes/Services/CheckoutReconciler.php
- Modify: tests/includes/CheckoutReconcilerTest.php
- Modify: includes/Services/PaymentSweeper.php
- Modify: tests/includes/PaymentSweeperTest.php
- Modify: includes/Plugin.php

**Interfaces:**
- Consumes: ConnectionService::for_new_checkout and for_identity plus the SquareOperationRunner from Task 6.
- Produces: immutable attempt identity and connection-aware adapter resolution for every Square operation.
- Changes: detached work becomes structured abandoned attempts, with a legacy-string read path.

- [ ] **Step 1: Write reference-format tests**

~~~php
$identity = ConnectionIdentity::oauth(
    'AAAAAAAAAAAAAAAAAAAAAA',
    7,
    'sandbox',
    'merchant_1',
    'location_1'
);

self::assertSame('sq_AAAAAAAAAAAAAAAAAAAAAA_2r', $identity->reference_id(99));
self::assertLessThanOrEqual(40, strlen($identity->reference_id(PHP_INT_MAX)));
self::assertSame(99, ConnectionIdentity::parse_order_id('sq_AAAAAAAAAAAAAAAAAAAAAA_2r'));
~~~

Also test malformed routing IDs, uppercase/non-base36 order IDs, overflow, manual reference woocommerce_order_99, and route ID mismatch.

- [ ] **Step 2: Run and verify RED**

~~~bash
composer test -- --filter ConnectionIdentityTest
~~~

Expected: FAIL because ConnectionIdentity does not exist.

- [ ] **Step 3: Implement the value object**

The serialized identity contains method, connection_id, local_generation, broker_generation, environment, merchant_id, location_id, and manual_fingerprint. OAuth reference IDs use the full 128-bit routing ID and base36 order ID; manual/pre-upgrade references remain unchanged.

~~~php
final class ConnectionIdentity {
    public static function from_array(array $value): self {}
    public function to_array(): array {}
    public function reference_id(int $order_id): string {}
    public static function parse_order_id(string $reference): ?int {}
    public function matches_reference(string $reference): bool {}
}
~~~

- [ ] **Step 4: Write failing OrderMeta lifecycle tests**

Test that start_attempt records the serialized identity before Square is called; close_current_attempt copies it into history; detach writes:

~~~php
array(
    'checkout_id' => 'chk_1',
    'identity' => $identity->to_array(),
    'started' => 1_700_000_000,
)
~~~

to _sqtwc_abandoned_attempts; clear_current_pointers removes active identity. Legacy _sqtwc_abandoned_checkout_ids strings remain unbound work until LegacyAttemptBinder proves them against the one imported manual candidate; they are rewritten only after validation.

- [ ] **Step 5: Change AjaxHandler to resolve per operation**

Constructor accepts ConnectionService and a connection-aware adapter factory backed by SquareOperationRunner rather than one request-global adapter. New checkout resolves active identity once, builds reference_id from it, stores identity before Square create, and uses its exact client. Status/cancel/detach resolve the identity already on the attempt, rejecting device/checkout/connection mismatches.

~~~php
$identity = $this->connections->for_new_checkout(Settings::get_environment());
OrderMeta::start_attempt(
    $order,
    $attempt_id,
    $idempotency_key,
    $device_id,
    $create_data,
    $identity->to_array()
);
$adapter = $this->adapters->terminal($identity);
~~~

- [ ] **Step 6: Make reconciliation identity-aware**

CheckoutReconciler validates both reference format and the exact recorded identity. It never compares an old checkout against the current active pointer. Payment GetPayment calls use the same attempt adapter. A completed-but-unreconciled retired connection remains usable only for its recorded attempt.

~~~php
$identity = ConnectionIdentity::from_array(OrderMeta::current_identity($order));
if (!$identity->matches_reference((string) $checkout['reference_id'])) {
    return array('applied' => false, 'reason' => 'connection_mismatch');
}
$adapter = $this->adapters->terminal($this->connections->for_identity($identity));
~~~

- [ ] **Step 7: Refactor the sweeper around structured work items**

For each current or abandoned attempt, resolve its own identity and adapter, fetch, reconcile, and remove only that exact finalized work item. An unavailable retired credential logs a stable error and keeps the order indexed. Do not cache one adapter across orders or attempts.

~~~php
foreach (OrderMeta::reconciliation_work($order) as $work) {
    $identity = ConnectionIdentity::from_array($work['identity']);
    $connection = $this->connections->for_identity($identity);
    $adapter = $this->adapters->terminal($connection);
    $checkout = $adapter->get_checkout($work['checkout_id']);
    $this->reconciler($adapter)->reconcile($checkout, $order, array('identity' => $identity));
}
~~~

For a legacy item with no identity, LegacyAttemptBinder may call GetTerminalCheckout with only the imported-at-upgrade manual candidate. Bind only when the returned checkout has the exact woocommerce_order_<id> reference, expected location/environment, and the candidate token has a validated matching merchant. Not-found, unauthorized, mismatch, timeout, or provider ambiguity leaves the item unresolved/manual_review and does not try another token.

- [ ] **Step 8: Add adversarial lifecycle tests**

Cover:

- switch manual to OAuth with a pending manual checkout;
- OAuth account A to account B with pending/detached work;
- delayed status from old generation;
- completed-but-unreconciled old attempt after replacement;
- retired connection cannot create new checkout;
- disconnect/change token/location blocked while referenced;
- forced merchant-wide revoke marks affected orders for manual review;
- legacy pre-upgrade attempt reconciles through imported manual identity.
- settings changed before upgrade: the candidate cannot prove ownership, no
  identity is written, and the order remains indexed for manual review.

~~~php
public function test_account_switch_does_not_rebind_detached_attempt(): void {
    $this->order->update_meta_data('_sqtwc_abandoned_attempts', array($this->account_a_work));
    $this->store->activate('sandbox', 'account_b', 1);

    $this->sweeper->sweep();

    self::assertSame('account_a', $this->adapter_factory->resolved_connection_ids[0]);
}
~~~

- [ ] **Step 9: Run validation and commit**

~~~bash
composer lint
composer test
composer test:js
git diff --check
git add includes tests
git commit -m "feat: bind Terminal attempts to Square connections"
~~~

---

### Task 10: Relayed webhook verification and connection-aware reconciliation

**Repository:** wcpos/square-terminal-for-woocommerce

**Files:**
- Create: includes/Services/RelaySignatureVerifier.php
- Create: tests/includes/RelaySignatureVerifierTest.php
- Create: includes/RelayWebhookHandler.php
- Create: tests/includes/RelayWebhookHandlerTest.php
- Create: includes/Services/WebhookEventProcessor.php
- Create: tests/includes/WebhookEventProcessorTest.php
- Modify: includes/WebhookHandler.php
- Modify: tests/includes/WebhookHandlerTest.php
- Modify: includes/Plugin.php
- Modify: includes/Connections/ConnectionAdminController.php

**Interfaces:**
- Consumes: relay key set, ConnectionService::for_identity, ConnectionIdentity reference parsing.
- Produces: REST POST /sqtwc/v1/relay with distinct WCPOS signature verification.
- Preserves: REST POST /sqtwc/v1/webhook for direct manual Square webhooks.

- [ ] **Step 1: Write failing relay signature tests**

The verifier accepts exact timestamp, connection ID, callback path, event ID, key ID, and raw body. Test active key, previous key before deadline, previous key after deadline, unknown key ID, body/path tampering, wrong connection, timestamp older/newer than five minutes, malformed headers, and constant-time signature mismatch.

~~~php
self::assertTrue($verifier->verify(
    $raw_body,
    array(
        'x-wcpos-timestamp' => '1700000000',
        'x-wcpos-connection-id' => 'AAAAAAAAAAAAAAAAAAAAAA',
        'x-wcpos-event-id' => 'event_1',
        'x-wcpos-key-id' => 'relay-2026-07',
        'x-wcpos-signature' => $signature,
    ),
    '/wp-json/sqtwc/v1/relay',
    $relay_keys,
    1700000001
));
~~~

- [ ] **Step 2: Run and verify RED**

~~~bash
composer test -- --filter "RelaySignatureVerifierTest|RelayWebhookHandlerTest"
~~~

Expected: FAIL because relay classes do not exist.

- [ ] **Step 3: Extract verified event processing**

Move JSON parsing, supported-event filtering, reference parsing, order lookup, event dedupe, order lock, identity-aware adapter resolution, reconciliation, and retry rollback into WebhookEventProcessor. Existing WebhookHandler keeps Square signature verification then calls the processor in manual mode. RelayWebhookHandler verifies WCPOS signature/connection identity then calls the same processor.

~~~php
final class WebhookEventProcessor {
    public function process(string $body, ?string $required_connection_id = null): array {}
}
~~~

- [ ] **Step 4: Register the distinct relay route**

Plugin.php registers POST /sqtwc/v1/relay with public permission callback because cryptographic verification is the authorization. Read the raw body once. Do not accept Square's header on the relay route or WCPOS relay headers on the direct route.

~~~php
register_rest_route(
    'sqtwc/v1',
    '/relay',
    array(
        'methods' => 'POST',
        'callback' => array($this, 'handle_relay_webhook'),
        'permission_callback' => '__return_true',
    )
);
~~~

- [ ] **Step 5: Implement heartbeat scheduling**

After successful refresh and at most once daily during authenticated Square activity, enqueue a non-blocking heartbeat. Low-traffic cron remains a supplement, not the only route repair. Heartbeat updates active/previous relay keys atomically and never changes the payment connection method.

~~~php
if ((time() - (int) $connection['last_heartbeat']) >= DAY_IN_SECONDS) {
    wp_schedule_single_event(time() + 1, 'sqtwc_square_heartbeat', array($connection['id']));
}
~~~

- [ ] **Step 6: Add end-to-end handler tests**

Test duplicate 200, unsupported 202, bad signature 401, malformed body 400, route/reference connection mismatch 400, retired matching identity accepted for reconciliation, wrong merchant/environment rejected, provider failure 500 with event ID rollback, and direct/manual behavior unchanged.

~~~php
public function test_provider_failure_rolls_back_event_dedupe(): void {
    $response = $this->handler->handle($this->event_body, $this->valid_headers);
    self::assertSame(500, $response['status']);
    self::assertSame(array(), $this->order->get_meta('_sqtwc_processed_event_ids'));
}
~~~

- [ ] **Step 7: Run full plugin validation and commit PR E**

~~~bash
composer lint
composer test
composer test:js
git diff --check
git add includes tests
git commit -m "feat: reconcile relayed Square webhooks"
~~~

Open PR E with explicit companion links to wcpos-com PRs A/B and plugin PRs C/D.

---

### Task 11: Deployment gates, hosted verification, documentation, and release

**Repositories:** wcpos/wcpos-com and wcpos/square-terminal-for-woocommerce

**Files:**
- Create: wcpos-com docs/runbooks/square-oauth-broker.md
- Modify: square-terminal-for-woocommerce docs/testing/square-terminal-validation.md
- Modify: square-terminal-for-woocommerce README.md
- Modify: square-terminal-for-woocommerce readme.txt
- Modify: square-terminal-for-woocommerce CHANGELOG.md
- Create: square-terminal-for-woocommerce docs/releases/0.3.0.md
- Modify: square-terminal-for-woocommerce square-terminal-for-woocommerce.php

**Interfaces:**
- Consumes: all prior tasks and deployed broker protocol v1.
- Produces: operator runbook, evidence-backed release, and distributable plugin artifact.

- [ ] **Step 1: Add broker deployment/configuration runbook**

Document exact environment variables, Square redirect URLs, sandbox/production webhook URLs, required event subscriptions, key rotation order, Redis no-TTL registry metadata backup, sensitive vault-backup retention/access controls, vault encryption-key recovery, Redis-loss fail-closed behavior, alerts for authorization-store failures, rollback order, and how to disable Connect through capabilities without affecting manual mode. A restored snapshot is never active authorization state: disable OAuth capabilities, restore metadata, run the fail-closed sanitizer to mark every non-revoked connection reconnect_required and purge all restored vault/operation/route secrets, verify its completion marker, then re-enable capabilities. Encryption-key recovery may decrypt an authorized live vault but must never reactivate per-connection material deleted from live Redis. Never include actual secret values.

~~~text
Required headings:
1. Square application configuration
2. Vercel environment variables
3. Redis key classes and TTL policy
4. Signing/encryption/relay key rotation
5. Fail-closed snapshot recovery and sensitive vault-backup retirement
6. Broker rollback and capability shutdown
7. Alerts, dashboards, and secret-safe incident response
~~~

- [ ] **Step 2: Run wcpos.com pre-deploy validation**

~~~bash
pnpm lint
pnpm type-check
pnpm test:unit
pnpm test:e2e
pnpm build
git diff --check
~~~

Expected: all commands PASS. Record exact counts and environment in the PR.

- [ ] **Step 3: Deploy broker before exposing the plugin**

Verify on the deployed host:

~~~text
GET /api/integrations/square/v1/capabilities
protocolVersion = 1
sandbox = true
production reflects configured production readiness
no cache/proxy leaks secret-bearing responses
~~~

Confirm authoritative Redis connection and encrypted live refresh-vault records have no TTL, operation records have bounded expiry, and route-cache records have 35-day TTL. Perform the pre-disconnect-snapshot restore drill with OAuth capabilities disabled:

~~~bash
pnpm square:restore-fail-closed -- --environment sandbox
~~~

Require a completed sanitizer marker, `reconnect_required` for every restored non-revoked connection, no restored vault/operation/route keys, refresh rejection, and zero ObtainToken calls before capabilities can be enabled. Inspect redacted response schemas and a test WordPress database to prove no refresh token crossed into WordPress.

- [ ] **Step 4: Hosted sandbox verification on dev-pro.wcpos.com**

Execute and record:

1. Fresh Connect with no prior settings.
2. Existing Square browser session still requires the intended merchant path and explicit Use this Square account.
3. Single and multiple locations.
4. Sandbox exposes only magic devices and performs no pairing.
5. Every Square magic-device checkout scenario.
6. Relay success and deliberately blocked relay with polling/sweeper recovery.
7. Refresh plus durable acknowledgement; capture a redacted request/response schema proving no refresh-token field leaves the broker.
8. Disconnect proves the vault entry is gone before access-token revocation and no further refresh/Square ObtainToken call is possible.
9. Two sites connected to one merchant: disconnect one; the other still refreshes and pays.
10. Upgrade a copy of production-style manual settings and pay without switching.
11. Switch to OAuth then attempt code-only downgrade; payments fail closed.
12. Explicitly restore validated manual mode, downgrade, and verify manual continuity.

~~~text
Evidence record for every scenario:
status: PASS | FAIL | UNVERIFIED
plugin commit:
broker commit:
site URL:
Square environment:
request IDs:
order ID:
screenshots/log query:
observed result:
~~~

- [ ] **Step 5: Controlled production Terminal verification**

With explicit approval for a low-value live payment:

1. Create a code for a controlled production location.
2. Let one code expire and generate another.
3. Pair a physical Square Terminal.
4. Verify Device Code and ListDevices pagination/ID mapping.
5. Reload and rediscover the same checkout device ID.
6. Complete one approved low-value payment.
7. Cancel/reconcile a second attempt.
8. Verify no customer/access-token/refresh-token/raw webhook data in logs and no refresh token in WordPress options, transients, HTTP captures, or rendered payloads.

This step must be PASS before Step 6 begins. If physical hardware is unavailable or any production pairing/payment observation is UNVERIFIED, stop the release: do not bump to 0.3.0, build the distributable, merge the release PR, tag, or publish while production pairing remains in scope.

~~~text
Live verification guard:
- controlled WCPOS merchant and location confirmed
- physical Terminal serial recorded outside public logs
- maximum approved amount recorded
- refund/cancellation owner identified
- customer data disabled
- start and completion timestamps recorded
~~~

- [ ] **Step 6: Run plugin release validation**

Precondition: Step 5 evidence status is PASS. Any FAIL or UNVERIFIED result stops here.

~~~bash
composer lint
composer test
composer test:js
composer build:scoped-vendor
git diff --check
~~~

Build the distributable zip through the repository's release workflow, install that exact zip on the staging WordPress site, and repeat one sandbox connect/discovery/payment/disconnect smoke. Testing a source checkout is not artifact verification.

- [ ] **Step 7: Adversarial and security review the final implementation**

Run correctness, critic, Codex review, adversarial review, and security review gates. Required focus:

- OAuth state/ticket replay;
- origin/merchant/environment binding;
- Redis loss and lock lease expiry;
- refresh/disconnect token resurrection;
- multi-site seller revocation;
- SSRF/DNS rebinding/proxy bypass;
- relay key rotation;
- secrets in logs/URLs/HTML;
- stale WordPress CAS;
- interrupted WordPress transition-journal recovery at every phase;
- historical attempt identity;
- downgrade safety.

Fix all validated critical/high findings and rerun full validation before release.

~~~bash
git diff --check
composer lint && composer test && composer test:js
pnpm lint && pnpm type-check && pnpm test:unit && pnpm build
~~~

- [ ] **Step 8: Update release documentation for 0.3.0**

The repository's authoritative tags currently end at v0.2.2; this backward-compatible feature release is 0.3.0. Recheck tags immediately before release and stop if 0.3.0 has been published elsewhere. Update plugin header, VERSION constant, readme stable tag, CHANGELOG, README setup/migration/downgrade instructions, validation doc, and docs/releases/0.3.0.md together. Include a dedicated Behavior changes / regressions section covering managed-broker dependency, direct-OAuth downgrade failure, manual insufficient-scope fallback, and production pairing verification status.

~~~bash
git tag --list 'v0.3.0'
rg -n 'Version:|Stable tag:|VERSION' square-terminal-for-woocommerce.php readme.txt
~~~

- [ ] **Step 9: Commit, push, and open the release PR**

~~~bash
git add square-terminal-for-woocommerce.php readme.txt README.md CHANGELOG.md docs
git commit -m "chore: prepare managed Square connection release"
git push -u origin codex/square-oauth-terminal-onboarding
gh pr create --base main --title "Add managed Square Terminal onboarding" --body-file /tmp/square-terminal-pr.md
~~~

The PR body links all broker/plugin companion PRs, records every validation command, separates Observed/Inferred/Unverified claims, and lists behavior changes/regressions.

- [ ] **Step 10: Release only after merge and deployment order is satisfied**

Broker authorization core deploys first, relay second, plugin release last. Confirm production capabilities before publishing the plugin artifact. Tag and publish through the existing GitHub release workflow; never hand-build a different artifact after smoke testing.

~~~bash
RUN_ID=$(gh run list --workflow release.yml --branch main --limit 1 --json databaseId --jq '.[0].databaseId')
gh run watch "$RUN_ID" --exit-status
gh release view v0.3.0 --json tagName,assets,url
~~~

The merge that changes the plugin header triggers release.yml automatically. If no run was created, use gh workflow run release.yml --ref main, then watch that newly created run; use the force input only to replace a known-bad existing artifact.

---

## Final Acceptance Checklist

- [ ] Existing manual merchants pay without reconnecting after upgrade.
- [ ] New merchants see one primary Connect to Square action.
- [ ] OAuth permissions are exact and merchant identity is explicitly confirmed.
- [ ] Locations and production Terminals are discovered without free-text IDs in OAuth mode.
- [ ] Pairing works only through valid Terminal API Device Codes.
- [ ] Every Square operation resolves the attempt's immutable connection identity.
- [ ] One site's disconnect never revokes another site for the same merchant.
- [ ] Superseded and in-flight access tokens are revoked by the two-phase state machine.
- [ ] Refresh tokens remain exclusively in the encrypted broker vault and are deleted before disconnect provider calls.
- [ ] Square-event revocation comparisons use immutable provider-derived authorization time, not broker wall-clock time.
- [ ] Every multi-option connection mutation recovers idempotently from its durable transition intent.
- [ ] Redis loss fails closed and cannot resurrect a refresh credential.
- [ ] Restoring a pre-disconnect snapshot purges all restored token capability and forces reconnect before broker capabilities return.
- [ ] Relay delivery is raw-body verified, connection-bound, key-rotatable, and DNS-pinned.
- [ ] Polling and sweeper reconcile correctly with relay unavailable.
- [ ] Direct downgrade from OAuth fails closed; explicit manual rollback is verified.
- [ ] Broker, source plugin, built zip, hosted sandbox, and controlled production hardware evidence are recorded separately.

## Execution Handoff

Plan execution should use **subagent-driven development**: one fresh implementation worker per task, with specification and quality review between tasks. Tasks 1–4 run in a wcpos-com worktree; Tasks 5–11 run in a square-terminal-for-woocommerce worktree. Do not allow both repositories to share a branch or commit history.
