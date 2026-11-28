# Prestmit implementation and completion status

**Reviewed:** 2026-11-27 · **Branch:** `arena/c90466f3-junior`

**Current verdict:** gift-card integration expanded and the reproduced financial
bug repaired in code. This is **not** a completed integration of every Prestmit
service and is **not approved for live funds**. No partner credentials were used.

## Implemented in this update

- PRESTMIT provider registration, encrypted API key/secret/PIN, official URL
  validation, balance and curated fixed-denomination buy catalogue sync.
- Customer checkout, wallet charging, encrypted delivery and buy reconciliation.
- NGN digital e-code selling: live-rate expiring quotes, ownership consent,
  encrypted submissions, staff dispatch, history and capped wallet settlement.
- Opt-in physical selling: catalogue form selection, encrypted private JPEG/PNG
  uploads, permission-gated audited staff viewing, multipart dispatch and the
  same authoritative settlement path as digital sales.
- Signed webhooks: provider-specific keys, base64 HMAC-SHA256 over raw bytes,
  constant-time verification, bounded bodies, supported event allowlist and
  local ownership/provider matching. Events trigger an authenticated API poll;
  their amount/status never directly credit or refund a wallet.
- Sensitive submitted codes and image manifests/files are purged by the sell
  cron 30 days after terminal settlement. Financial rows and keyed duplicate
  fingerprints remain. Unresolved submissions are not purged or resent.
- Read-only admin partner treasury: reported fiat balance, masked NGN/GHS bank
  accounts and paginated upstream payout history. Strong provider permissions,
  audited access and no-store responses; no transfer or bank-mutation actions.
- Migrations 045/046, regenerated new-install SQL and schema, incremental
  `database/upgrade-046-prestmit.sql` for existing version 044/045 databases,
  compiled frontend assets and a buildable cPanel deployment archive.

## Financial defect: original audit versus remediation

The initial audit **reproduced** this sequence offline:

| Step | Old transaction | Old order | Test wallet |
| --- | --- | --- | --- |
| Start | — | — | ₦500,000 |
| Buy ₦42,000 card; upstream create times out | PROCESSING | PLACED | ₦458,000 |
| Generic recovery after 48 hours | FAILED | PLACED | ₦500,000 |
| Provider later completes; collector runs | FAILED | DELIVERED | ₦500,000 |

That historical behaviour is now repaired:

1. `TransactionEngine::transition()` locks the parent transaction and applies
   one Prestmit refund policy for **generic recovery, direct admin refunds,
   cancellation, write-off and exception handling**. Unknown/pending/completed
   upstream purchases cannot be automatically refunded. Issued codes cannot be
   refunded. A confirmed FAILED/REFUNDED provider result is required, except a
   definitive local pre-acceptance rejection with no upstream reference.
2. Collection rejects terminal/refunded parents before contacting the provider,
   then locks/rechecks the parent again after the network call. Code storage,
   order delivery and parent SUCCESSFUL settlement share a database transaction.
3. `abandon()` no longer marks an order FAILED before a refund can succeed;
   failed transitions leave it open for reconciliation.
4. A durable internal lookup key is written **before** the buy call so a process
   or provider-log failure after acceptance remains recoverable.
5. New purchases pin provider type/environment in metadata. Reconciliation and
   refund fail closed if that identity changes. Open purchases prevent URL
   switching; Prestmit purchase/sale history prevents provider deletion.

Run the original no-network reproducer:

```sh
node tools/devserver/php_run.mjs tools/audits/prestmit_recovery_repro.php
```

It now exits **0 / AUDIT PASS**. Generic recovery issues **no refund**; late
collection produces SUCCESSFUL/DELIVERED with a wallet balance of **₦458,000**.
New regression tests cover generic recovery, direct admin refunds, issued-card
refund refusal, stale collection after refund, a parent closing during the
network call, identity changes and recovery after a logging failure. Controlled
interleaving/FakeDb tests are **not proof of native MySQL concurrency**.

## Follow-up: coupon/refund consistency and retention

New regression cases reproduced three additional defects before repair:

| Scenario | Before | Repaired behaviour |
| --- | --- | --- |
| Accepted buy followed by a local logging exception; refund blocked as uncertain | Purchase stayed charged but coupon was released | Coupon stays linked/used while PROCESSING; another discounted purchase is refused |
| Later confirmed failure/refund of a pending discounted purchase | Wallet refunded but coupon remained used | Coupon release and full refund commit or roll back together |
| Repeat release while another customer still uses the same coupon | Global usage was decremented twice | Locked deletion is idempotent and counters change only for the deleted row |

Coupon links are persisted before any charge/dispatch. Link failure sends no
provider request and moves no money. Release failure during reconciliation
rolls back wallet, parent/order state and coupon changes; a subsequent retry
can reconcile safely. Tests also preserve coupon usage for completed goodwill
refunds and keep zero-charge pending gift-card transactions behind provider
reconciliation. The recorded-purchase error message now explicitly says not to
submit a new purchase instead of implying that an error meant a refund.

The retention worker now rechecks both terminal status **and age** under its
sale-row lock, bounds its batch size and counts purges only after commit.
Unresolved submissions remain protected regardless of age. Existing encrypted
orphan-file cleanup still requires operator reconciliation; no unsafe generic
filesystem sweep was introduced.

Shared coupon regression suites pass: **CouponDomainsTest 13/106** and
**CouponRedemptionRaceTest 14/66** (tests/assertions). The prior three-failure
reproduction and the now-passing cases are in the unit suite. These are offline
controlled-interleaving tests, not native concurrency certification. Review
legacy unresolved purchases with coupon metadata but missing redemptions;
there is no speculative backfill of already-used/reused coupon slots.

## Follow-up: expiry, fair polling and settlement driver failures

Seven additional sale lifecycle regressions cover:

- Quote expiry between public prechecks and final digital/image acceptance;
  both originally accepted the stale quote. Final acceptance now uses an
  owner/form/status/expiry compare-and-set. Rejected image intake removes its
  newly written encrypted files and leaves no sensitive manifest on the quote.
- HTTP and provider-configuration failures at the head of small cron batches;
  failed attempts now rotate through the queue instead of starving later sales.
  `last_checked_at` means attempted reconciliation, not proof of a successful
  provider response. Its update intentionally survives settlement rollback.
- Refused transaction start and refused settlement commit, both of which used
  to return success. These boundaries are now checked and the uncommitted work
  is rolled back. Retrying settles once through the existing idempotency key.
- Partial physical-image deletion followed by retry: the manifest remains
  available until cleanup succeeds, missing previously deleted files are safe,
  and only one completed purge audit is retained. This is not filesystem/SQL
  atomicity or automatic orphan-file discovery.

The expiry and transaction-boundary tests were run against the old behaviour
and failed before repair. Tests use controlled interleavings and driver doubles;
a network-ambiguous native COMMIT still requires reconnecting and inspecting
sale/ledger state, not blind manual credit or resetting the transaction.
No new schema migration or money-moving API has been added in this increment.

## Follow-up: audit refusal and lost local commit acknowledgements

Seven regression tests first failed against a false-returning audit model:
unaudited digital/physical acceptance, local rejection, dispatch, image access,
settlement and purge. The shared sell audit helper now rejects both `false`
and exceptions. Local acceptance/rejection and the dispatch claim commit their
audit and state change together. The claim must commit successfully before an
upstream POST. A refused audit restores REVIEW and makes no provider call.
Settlement rolls back sale/ledger changes if its audit is refused; image access
returns no bytes. Purge audits before filesystem deletion so an audit refusal
cannot delete physical images. It still cannot make filesystem and SQL atomic.

Eleven new tests in total also prove that ambiguous local commit outcomes do
not authorize HTTP dispatch or deletion of possibly attached images. A simulated
persisted commit with a lost acknowledgement retains the SUBMITTING claim (no
blind resend) or the committed REVIEW image manifest (no deletion). A refused,
uncommitted physical commit likewise retains files conservatively for operator
review. Clean rollback before commit permits normal rejected-upload cleanup.
The `giftcard.sale.dispatched` audit is dispatch intent, not provider acceptance.

These guarantees are from offline fault injection. Native concurrency and
network-ambiguous commits still require isolated staging acceptance. No schema
migration or new money-moving endpoint was introduced.

## What is still NOT implemented

| Capability | Reason / required next action |
| --- | --- |
| Prestmit bank-account changes and bank payouts | Read-only bank lists, wallet details and payout history are built. Bank create/delete and payout initiation are not exposed. The payout guide says minor units and shows snake_case fields; the reference says a minimum of 1000 Naira with amount example 2700, camelCase fields and multipart. Confirm exact units, fees, PIN/2FA and idempotency contracts in sandbox before building/enabling a money-moving integration. |
| Cash-out of the customer's spending wallet | Deliberately not introduced. The existing app explicitly removed deposit-wallet withdrawals; its earnings payouts are a separate ledger. Gift-card sale credit is spending credit, not a bank transfer. Changing this requires a deliberate product/compliance and ledger-isolation design. |
| Cedis/crypto sell payouts | NGN wallet credit only. No implicit FX or crypto settlement. |
| Crypto-to-cash / OTC / eSIM / Prestmit airtime, data or bills | No approved partner contracts/access supplied. Existing VTU/number/KYC/payment adapters remain separate providers. No guessed endpoints or simulated live success were added. |
| Automated ownership/KYC, fraud scoring, disputes and chargebacks | Staff review and reference-based secure support exist; business/compliance procedures remain operator responsibilities. |

## Verification

- `PrestmitAdapterTest`: **31 tests, 179 assertions passed**.
- `GiftcardSellTest`: **54 tests, 447 assertions passed**.
- `PrestmitTreasuryTest`: **15 tests, 148 assertions passed**, with GET-only
  requests, masking, no ledger changes and failure-path coverage.
- `GiftcardsTest`: **97 tests, 482 assertions passed**. The previously missing
  `ReloadlyAdapter::configured()` method is implemented and covered.
- Original cross-worker reproducer: **PASS**, no network or live funds.
- PHP syntax parser: **642 application/test/audit-tool files passed**.
- JavaScript tests and compiled frontend build: **passed**.
- Production SQL freshness and static DB audit: **passed**.
- SQL replay validator: **544 statements, 109 tables, 163 foreign keys**;
  bookkeeping version **46**, no seeded user accounts, **passed**.
- Full PHP suite: **1,987 tests, 21,763 assertions, zero failures**. One test is
  skipped because WASM cannot express the native flock mutual-exclusion probe;
  that test must pass on native CI.
- Python schema-linter regressions: **4 passed**. The three initial schema-linter
  errors were false positives from ignoring ALTER ADD COLUMN; the parser now
  registers and validates those columns and still rejects invalid FKs/money types.
  The regenerated schema validates with **zero errors/warnings**.
- `npm run build` and `bash tools/build_deployment_package.sh`: **passed**.
  `application-deployment.zip` includes real CodeIgniter files, SQL, compiled
  assets, runtime deny guards and integration setup notes. Clean-extract/static
  package gates and the PHP package freshness/mail-fixture tests pass.
- The separate native-PHP fresh-deployment simulation was not run: no native PHP
  or MySQL server is installed in this workspace. Docker was not started.

The broader suite also exposed deployment hygiene problems: a missing tracked
session-directory placeholder, Docker mounting an absent uncompressed SQL file
instead of the existing `.sql.gz`, and a UI-component assertion applied to a
script-only partial. Those are repaired without suppressing secret/CSRF checks.
The mail-fixture/package checks require generating the deployment ZIP first.

## Production activation still requires external acceptance

1. Back up the deployment database and encryption key; apply all missing
   migrations. Do not import new-install SQL over an existing database.
2. Obtain approved isolated sandbox credentials and verify HMAC canonicalization,
   multipart binary uploads, exact history filters and response shapes, rate and
   settled amount units, fees/commission, and PIN/2FA policies.
3. Test native MySQL/MariaDB row locks, concurrent collect/refund and repeated
   admin/webhook/cron settlement. This workspace uses PHP 8.2 WASM/FakeDb.
4. Test real HTTP uploads, session/CSRF/permissions, private image denial and
   customer/admin browser journeys. Configure PHP/web-server request limits,
   private writable storage, disk monitoring and key-preserving backups.
5. Register the HTTPS webhook URL and separate signing key; prove valid, invalid,
   duplicate and delayed notifications. Keep both polling crons running.
6. Complete operational KYC/fraud/dispute procedures and approve the 30-day
   terminal-secret retention policy. Resolve uncertain trades with Prestmit;
   never reset SUBMITTING to REVIEW or retry money-moving creates blindly.
7. Only then explicitly opt in to sales/physical uploads with a separate live
   provider row. New sell/physical features remain **off by default**.

### Contract references for unresolved payout ambiguity

- https://documentation.prestmit.io/docs/payouts-wallet-details.md
- https://documentation.prestmit.io/reference/post_api-partners-v1-wallet-fiat-create-withdrawal.md
- https://documentation.prestmit.io/docs/setting-up-webhooks.md

**Bottom line:** real integration code, financial-state fixes, migrations,
regression tests and a deployment build have been produced. “Every Prestmit
service is finished and live-ready” would still be an inaccurate claim.
