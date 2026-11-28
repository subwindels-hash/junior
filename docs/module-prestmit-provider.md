# Prestmit provider — gift-card purchasing and opt-in selling

Status: implemented and offline-tested; **not sandbox-verified or production-approved**.
Reviewed against public Prestmit documentation on 2026-11-27.

> **Safety fix:** generic/admin recovery now shares the Prestmit refund guard,
> and collection locks/rechecks the parent before atomic delivery/settlement.
> The original cross-worker reproducer now passes. This is offline verification,
> not live-funds approval. See [completion status](prestmit-completion-audit.md).

## Scope (not a clone of Prestmit's entire consumer app)

| Service | This project / this change |
| --- | --- |
| Customers buy gift cards | PRESTMIT adapter connected to existing gift-card checkout, wallet, encrypted delivery and cron collection |
| Catalogue, partner balance | Implemented through existing Admin → Providers controls |
| Customers sell their gift cards to the business | Opt-in NGN e-code and physical-image workflows; see [sell setup](module-prestmit-sell.md) |
| Partner wallet / bank accounts / payout history | Read-only masked admin treasury inspection; bank changes and payout initiation are not implemented |
| Webhook settlement | Signed raw-body notifications trigger authoritative reconciliation; giftcard_codes and giftcard_sales polling remain the fallback |
| Crypto-to-cash, OTC, eSIMs, airtime and bill payments | Not integrated with Prestmit; do not route these to its gift-card API |
| Existing VTU, numbers, KYC, payment gateways | Unchanged; retain their existing providers |

Prestmit's public homepage labels gift-card API access live and eSIM/crypto APIs
"Soon". Consumer-app availability does not imply partner API availability.
No unsupported endpoints or pretend-success placeholders were added.

## Setup

1. Request partner API access. The authentication guide directs applicants to
   register at https://sandbox.prestmit.io and contact sales@prestmit.com.
   API support is partners@prestmit.com. Confirm current onboarding with Prestmit.
2. Install the application normally (existing Composer, DB migrations, master
   encryption key and cron requirements still apply). Apply migrations 045/046 with
   `php index.php migrate latest` for sell history and physical-image metadata. For cPanel upgrades from version 044/045 only, the package also contains `database/upgrade-046-prestmit.sql`. Older installs must apply every missing migration first. The buy adapter
   itself reuses the existing gift-card purchase tables.
3. In **Admin → Providers → Create provider**, choose **PRESTMIT · GIFTCARD**.
   Initially choose **INACTIVE**.
4. Sandbox URL: `https://dev-api.prestmit.io/partners/v1`.
   Live URL: `https://api.prestmit.io/partners/v1`.
   Only those exact base URLs (with an optional trailing slash) are accepted.
   The marketing website is not an API URL. Sandbox endpoints are blocked by
   production kernels; test them on an isolated non-production installation.
5. Enter the partner API key in the existing key field. Expand **Prestmit partner
   API** and enter the API secret and account PIN (preserving leading zeros).
   All three are stored together under authenticated encryption, never echoed
   into a form or audit payload. Never commit them, put them in browser JS, or
   send them in chat.
6. The panel base currency and provider currency must match, and be **NGN** or
   **GHS**. The API uses NAIRA/CEDIS payment methods. Do not change an established
   panel's base currency merely to pass this check: redenomination has its own
   operational workflow. There is no implicit foreign-exchange conversion here.
7. Use **Test connection**, **Sync balance**, then **Sync services**. Gift-card
   catalogue sync uses the configured `giftcard_countries` (default US).
8. Review products and brands in **Admin → Gift cards**. Imports are **inactive
   and unpriced**, not automatically sellable. A gift card's USD/EUR face value
   is not its NGN/GHS wholesale cost. Set a selling price with sufficient margin,
   set quantity limits, and explicitly activate only approved products/brands.
9. Fund the sandbox wallet using Prestmit's sandbox procedure; enable the
   sandbox provider and test all the cases below. Configure the existing
   `giftcard_codes` cron job; this integration does not require a webhook URL.
10. After sandbox acceptance and Prestmit approval, use a **separate live provider
    row and separately imported products**. Avoid switching an existing row's
    environment while it has open orders: their references belong to the old
    environment. Rotation requires the key, secret and PIN together; changing
    environment without replacing credentials is refused.

An account requiring a fresh withdrawal 2FA code for every debit cannot be
silently automated with a static code. Confirm the supported partner debit/2FA
policy with Prestmit; this integration does not store TOTP seeds, generate codes,
or bypass 2FA.

## Behaviour and safety

- Requests use the documented API-KEY + API-Hash HMAC-SHA256 scheme. The signature
  covers `API_KEY + ':' + JSON(body/query)`. Empty requests sign `{}`. The same
  JSON string is signed and sent for POSTs. This initial integration sends JSON,
  with no attachments; see the sandbox contract checks below.
- TLS verification and the existing SSRF protections remain enabled. The
  Prestmit adapter restricts hosts and disables redirects so authentication
  headers cannot follow a redirect to another origin.
- No HTTP request is automatically retried, including creates. A GET history
  lookup by `uniqueIdentifier` precedes a quote/create. The local transaction
  engine also handles repeated checkout idempotency keys.
- The quote is checked against actual transaction proceeds (after coupons)
  before create, so an already-outdated catalogue price is rejected. Prestmit
  does not document a quote-lock/max-debit parameter; a rate movement **between
  quote and create remains a provider-side risk**, not a guaranteed price lock.
- A create timeout, 5xx, ambiguous HTTP response or malformed success preserves
  a `prestmit-pending:<internal-reference>` reference. It stays PROCESSING and
  is reconciled through history by uniqueIdentifier; it is not bought again.
- A COMPLETED history status is not sufficient to settle locally. Usable codes
  must be fetched, match the ordered quantity, and pass through the existing
  encrypted storage/reveal-audit path. Partial multi-card results remain open.
- Prestmit orders cannot be abandoned/refunded through GiftcardService merely
  because the normal one-hour/six-attempt cutoff elapsed. A fresh, matching
  FAILED or REFUNDED provider status is required, for both cron and admin
  write-off. Unknown/pending/empty history remains open for manual reconciliation.
- Generic upstream error bodies are not surfaced because they may echo a PIN
  or card code. Diagnose with Prestmit using the reference, timestamp and HTTP
  status; never add raw authentication or card-code logging.
- Catalogue pagination is bounded, cached for the duration of one sync, and
  rebuilt against the configured official base URL rather than following
  upstream links. Pre-orders and cards without usable region metadata are
  skipped. Range products yield curated fixed denominations (min/max plus
  10/25/50/100 within bounds), all requiring operator review and a valid quote.
- Prestmit catalogue keys include provider row, SKU and recipient currency;
  syncing them must not overwrite a Reloadly product with the same brand/amount.

## Mandatory sandbox acceptance before live activation

Public guide examples have inconsistencies (older bearer-auth/multipart examples
versus the HMAC/JSON authentication guide, and old website URLs versus current API
hosts). No authenticated sandbox request was possible in this environment, and
outbound access to the Prestmit API hosts is not available here.

Verify with your approved sandbox account:

- Empty GET/query HMAC encoding and JSON numeric types/POST acceptance. If your
  account requires multipart requests, implement that transport and sign only
  the documented non-attachment fields; do not guess or change auth to bearer.
- History filters actually narrow the result and `uniqueIdentifier` matches
  exactly. Confirm duplicate identifier handling and history visibility delay.
- Calculate-payment returns the **total quantity-inclusive** NAIRA/CEDIS cost;
  confirm commissions and discounts against actual debits.
- Account PIN policy and whether automated buys require a per-action 2FA code.
- Success, pending, rejected, refunded, timeout-after-acceptance, invalid PIN,
  insufficient partner funds, empty codes and multi-card partial delivery.
- Customer debit once, encrypted delivery, reveal ownership/audit, and a refund
  once only after authoritative failure. Never blindly retry an unknown create.
- Cron recovery after process termination around dispatch. A crash between
  upstream acceptance and local reference persistence needs operator reconciliation
  by the transaction public_id; do not manually refund until checked upstream.

Do not use live customer money until these checks are complete.

## Tests run

```sh
node tools/devserver/php_run.mjs tools/phpunit_lite.php PrestmitAdapterTest
node tools/devserver/php_run.mjs tools/phpunit_lite.php ProvidersTest
node tools/devserver/php_run.mjs tools/phpunit_lite.php BestdataAdapterTest
node tools/devserver/php_run.mjs tools/phpunit_lite.php GiftcardsTest
node tools/php_syntax_check.mjs application tests/unit/PrestmitAdapterTest.php
npm run test:js
```

Current results: the complete offline PHP suite reports **1,987 tests,
21,763 assertions, zero failures, one WASM file-locking skip**. The missing
Reloadly `configured()` method is repaired. The financial-state reproducer,
PHP syntax checks, JavaScript tests, frontend build and deployment archive
validation pass. SQL freshness, the schema linter and production SQL replay
also pass. These results are not authenticated sandbox or native MySQL proof.
See [completion status](prestmit-completion-audit.md) for the full test summary.

## Remaining implementation / acceptance

1. **Gift cards:** e-code and separately enabled physical-image selling, quotes,
   dispatch, reconciliation and NGN wallet credit are implemented. Authenticated
   API compatibility, fraud/dispute procedures and native concurrency acceptance
   remain necessary before activation.
2. **Signed webhooks:** implemented as authenticated reconciliation hints with
   idempotent settlement. Keep polling; verify the actual console event payloads
   in staging. No undocumented timestamp/event-ID replay guarantee is claimed.
3. **Bank payouts:** not implemented. Resolve the guide/reference amount-unit and
   field-name contradictions with Prestmit first; then add beneficiary ownership,
   reservations, permissions, limits, duplicate protection and confirmed payout
   settlement/reversal. Do not turn spending-wallet credit into bank cash-out.
4. **eSIM/crypto/VTU:** approved partner API contracts/access are required. Keep
   existing providers until separate integrations can actually be implemented.

## Official contract sources

- https://documentation.prestmit.io/docs/getting-started
- https://documentation.prestmit.io/docs/getting-started-api-keys-authentication
- https://documentation.prestmit.io/docs/fetch-giftcards
- https://documentation.prestmit.io/docs/creating-a-buy-transaction
- https://documentation.prestmit.io/docs/tracking-your-buy-transaction
- https://documentation.prestmit.io/reference/get_api-partners-v1-general-user
- https://documentation.prestmit.io/docs/creating-sell-gift-card-transaction
- https://prestmit.io/

## Signed webhook configuration

In Prestmit's developer console register `https://YOUR-DOMAIN/webhook/prestmit/PROVIDER_ID`.
Set a provider-ID-to-signing-key map in `.env` (actual secrets only on the server):

```dotenv
PRESTMIT_WEBHOOK_SECRETS={"123":"replace-with-the-console-webhook-signing-key"}
```

Use the separate console webhook signing key, **not the API secret**, at least
16 characters. One URL is registered per API key. The header is
`X-Prestmit-Signature = base64(HMAC-SHA256(raw_body, webhook_key))`.
Unsupported events and unknown references are ignored. Invalid signatures return
401; invalid JSON returns 400; oversized bodies return 413; transient settlement
failures return 503. All responses omit event contents/secrets.

Events are reconciliation hints, not settlement proof. They cannot choose a
customer, wallet, payout amount or status. Repeated delivery converges through
row locking and ledger idempotency; the provider's sample has no documented
signed timestamp/event ID, so no timestamp-based replay guarantee is claimed.
Terminal repeats do not call the provider again. Polling remains required for
missed events and callbacks without the documented partnersApiIdentifier.
Withdrawals and other non-gift-card events do not change local payouts.

## Read-only partner treasury

Open **Admin → Providers → Prestmit provider → View treasury (read only)**.
The route is `/admin/providers/{public_id}/treasury` and requires **both**
`providers.view` and `providers.manage`. It is GET-only and responds with
`Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`.

The panel uses signed GET requests to the documented fiat wallet details,
NAIRA/CEDIS bank-account list and payout-history endpoints. It supports the
reference's top-level history list and the guide's nested history envelope.
Each section reports errors separately; unavailable balances are never shown
as zero. Pagination uses bounded local page numbers and never follows a
provider-supplied URL. Responses are projected before reaching the view:
full account numbers, holder names, private correlation identifiers, arbitrary
nested fields and receipt URLs are excluded. Account numbers show last four
characters only, and malformed/short numbers stay fully hidden.

This is the **partner account's** upstream data. It proves neither local
customer ownership of a bank account nor permission to pay it. Amounts are
reported without conversion, clearly labelled **units unverified**. A provider
COMPLETED status is informational only: it cannot settle a local payout, change
a spending wallet, or release earnings. No transfer/create/delete endpoint is
exposed. New treasury reads require a successful access audit; raw provider
responses and bank details are not stored in audit logs or sessions. A per-staff
refresh budget limits normal operation to ten inspections per minute; this is
not an atomic anti-abuse or money limit.

Read-only inspection works for inactive provider rows to aid reconciliation.
Production still refuses sandbox URLs. No new database migration or feature
flag is required for this read-only page. Authenticated sandbox and actual
browser permission/cache/masking acceptance are still outstanding.

References:
- https://documentation.prestmit.io/reference/get_api-partners-v1-wallet-fiat-details.md
- https://documentation.prestmit.io/reference/get_api-partners-v1-bank-accounts-naira-list.md
- https://documentation.prestmit.io/reference/get_api-partners-v1-bank-accounts-cedis-list.md
- https://documentation.prestmit.io/reference/get_api-partners-v1-wallet-fiat-withdrawal-history.md

Targeted treasury regressions: **15 tests, 148 assertions passed**, including
signed GET-only requests, masked projections, both response envelopes,
malformed data, missing totals, pagination bounds, audit failure, refresh limits,
independent section outages and unchanged local financial ledgers.

## Coupon and timeout follow-up hardening

Coupon reservations are now linked to their purchase **before** charging or
calling Prestmit. If a dispatch/logging failure leaves a purchase PROCESSING,
its coupon stays used rather than becoming available for a second discounted
purchase. Once a pending purchase's definitive failure and full local refund
commit, its coupon is returned in the same database transaction. Partial refunds
and goodwill refunds of already completed purchases do not reset usage.

Coupon deletion/counter changes are row-locked and transactional. Repeating a
release is a no-op; it cannot reduce another customer's global usage. A failure
to link the coupon stops before charging or provider dispatch. A failure to
return its slot during reconciliation rolls back the refund/state change for
safe retry. The engine's Prestmit closure policy also covers zero-charge
pending transactions, even though the normal Prestmit checkout still requires
provider cost to fit within actual sale proceeds after coupons.

A recorded PROCESSING purchase that encounters a local dispatch/logging error
returns `RECONCILIATION_REQUIRED` with an explicit instruction **not to submit a
new purchase**. Its existing receipt remains the customer destination. This is
not a claim that it was refunded or that the upstream request was never accepted.

Existing pre-upgrade unresolved purchases with coupon metadata but no matching
redemption need an operator review. This patch does not guess or recreate past
coupon use: a coupon may already have been reused elsewhere. Reconcile by
transaction reference, ledger and provider history before any repair.
