# Prestmit gift-card selling: e-codes and physical images

Implemented as an opt-in workflow; offline-tested, **not authenticated-sandbox
verified or cleared for live customer funds**. This adds selling to the existing
[Prestmit purchasing integration](module-prestmit-provider.md), not bank payouts,
crypto conversion or eSIM provisioning. Physical-card images are separately opt-in.

> **Not cleared for live funds:** the reproduced buy/recovery defect has now
> been fixed in code and its offline reproducer passes. Authenticated sandbox,
> native-database concurrency and browser acceptance remain outstanding.
> See [completion status](prestmit-completion-audit.md).

## What customers and staff can do

- Customer: **Dashboard → Gift cards → Sell a gift card**.
- Fetch currently sellable **Ecode** variants and NAIRA availability from Prestmit.
  Physical variants are excluded unless `PRESTMIT_PHYSICAL_ENABLED=true`; they always require images rather than accepting a code as a workaround.
- Request a server-side quote, valid for five minutes. Terms, rate, face value,
  customer/wallet/provider identity, provider environment and margin are frozen.
- Confirm ownership/variant/payout terms and submit one digital code. Codes are
  encrypted and are never displayed again, sent to sessions, passed to views,
  stored in audit metadata or included in provider-error messages.
- Staff: **Admin → Gift cards → Review customer sales**. With `giftcards.manage`,
  submit the encrypted code to Prestmit or decline it **before** dispatch.
- Admins with `giftcards.refund` can reconcile a submitted sale. This stronger
  permission is needed because reconciliation can credit a customer wallet.
  The cron worker performs the same reconciliation automatically.
- Customers see paginated quotes, sale states, estimated maximums and actual
  credited amounts. The wallet transaction type is `GIFTCARD_SALE`, not a deposit
  or a refund of money the user never spent.

## Installation and controlled activation

1. Back up the live database and retain the master encryption key.
2. Deploy the code and apply **migrations 045/046** using:
   `php index.php migrate latest`.
   On a new empty installation use the regenerated `database/marvysocials.sql`.
   **Never import that full new-install SQL over an existing live database.**
3. Configure an approved PRESTMIT provider through Admin → Providers. The
   provider's currency, panel base currency and customer wallet must all be NGN
   for this first sell implementation. Buying still supports matching NGN/GHS;
   selling has no implicit Cedis or crypto conversion.
4. Use a **non-production, isolated sandbox installation** for initial testing.
   Production kernels reject Prestmit's sandbox URL for both buying and selling.
5. The provider detail credential dialog shows its numeric sell-workflow ID.
   Set these non-secret environment variables, then reload application config:

   ```dotenv
   PRESTMIT_SELL_ENABLED=true
   PRESTMIT_SELL_PROVIDER_ID=<the configured numeric provider row ID>
   PRESTMIT_SELL_MARGIN_PERCENT=5
   ```

   The provider must be ACTIVE and API type PRESTMIT. Valid margins are 0–50%,
   with at most four decimal places. Missing/invalid configuration fails closed.
   Keep API keys, secrets and PINs in the existing encrypted provider credential
   fields, never in source control or browser JavaScript.
6. Install the additional cron entry from `cron/crontab.example`:
   `*/2 * * * * ... php index.php cron giftcard_sales`.
   It is also registered in the built-in cron scheduler/admin controls and
   labelled as money-moving. Monitor failed jobs and unresolved submissions.
7. Complete the sandbox acceptance checklist below before explicitly opting in
   on production with a **separate live provider row** and approved live keys.

Setting `PRESTMIT_SELL_ENABLED=false` blocks new quotes, submissions and
admin dispatches. It intentionally **does not stop reconciliation** of existing
sales. To pause settlement during an incident, use Admin → System → Cron jobs
and ensure nobody triggers an admin reconciliation while investigating.

## States and money policy

`QUOTED → REVIEW → SUBMITTING → PENDING → CREDITED / REJECTED`

- QUOTED contains no bearer code. Twenty quotes per customer per UTC day and a
  face-value limit of 10,000 bound this initial flow. This is a product limit,
  not a substitute for wider account/IP anti-abuse controls.
- Quote confirmation encrypts the code and stores a keyed, normalized-code
  fingerprint. Duplicate codes are refused without exposing another customer's
  identity. Retain the encryption key: replacing it invalidates decryption and
  changes fingerprints. Use the platform's key-management procedures.
- REVIEW is an unsubmitted code awaiting staff review. It can only be declined
  locally in this state; customers cannot cancel after confirmation.
- REVIEW → SUBMITTING is an atomic compare-and-set, committed **before** the one
  upstream POST. Concurrent clicks cannot both win. Neither the HTTP client nor
  this service retries a sell create.
- A timeout, lost response, process exception or accepted response without a
  reference stays SUBMITTING. It is polled by `uniqueIdentifier=public_id`.
  Empty history, unknown status and provider outages never cause a credit or
  a resubmission. **Never reset SUBMITTING to REVIEW to force a retry.**
- A definitive provider validation rejection becomes REJECTED; an accepted
  create becomes PENDING. No wallet money moves in either case.
- A fresh, matching provider history result must report COMPLETED, the same
  gift-card variant, the expected reference, and valid positive NGN proceeds.
  A mismatched payoutMethod, if returned, is refused. The documented NGN
  `totalAmount` settlement semantics must be confirmed with Prestmit in sandbox.
- Final credit is `min(quoted maximum, actual NGN proceeds × (1 − frozen margin))`.
  Customers explicitly accept that a lower accepted value can reduce the payout;
  a provider response can never credit more than the accepted quote. Example:
  face value 100 × rate ₦900 = ₦90,000; a 5% margin yields a maximum ₦85,500.
  If confirmed proceeds fall to ₦80,000, credit is ₦76,000. Changing the site
  margin later does not reprice an already accepted sale.
- Settlement locks the sale row (`SELECT ... FOR UPDATE`) and its wallet,
  verifies ownership/currency, calls LedgerService with the stable unique key
  `giftcard-sale:<public_id>`, then updates the sale and audit inside the same
  transaction. Error paths roll back ledger, balance, audit and state together.
- Already CREDITED/REJECTED rows are terminal no-ops. Provider REJECTED never
  credits money. No customer debit, advance payment or bank transfer is made.

## Operational safeguards and limitations

- Provider URL/environment is pinned to each quote. A changed environment
  cannot dispatch/reconcile that sale. Changing a provider URL with open sales
  is refused; providers with any sell history cannot be deleted. Disable them
  rather than destroying financial identity/history.
- This implementation does not reveal submitted codes to staff. Dispatch
  decrypts only in server memory and sends the code to Prestmit as `comments`.
  Physical images have a separate hardened intake; arbitrary customer comments or unsigned webhook-credit endpoints are not exposed.
- Sell create uses the documented **multipart/form-data** format. HMAC signs
  JSON of the same non-attachment fields. Query requests use the existing
  signed request path. Redirects and automatic retries remain disabled.
- No live identity/KYC, gift-card ownership verification, fraud scoring, chargeback
  resolution or bank cash-out is implied by admin review. Establish those
  business/compliance procedures and obtain Prestmit's approval before launch.
- Customer disputes and provider rejection proofs should go through your secure
  support process using sale references; do not paste bearer codes into tickets.
  Codes/images remain encrypted for dispute retention. The sell cron purges their sensitive payloads 30 days after CREDITED/REJECTED; unresolved trades are retained for reconciliation. Financial history and keyed fingerprints are retained. Approve this policy before launch.
- An abandoned SUBMITTING row may mean the process died before the HTTP call,
  or that the provider accepted and history is not visible yet. These cannot
  safely be distinguished locally. Obtain provider confirmation before any
  manual remediation; there is deliberately no blind resend button.
- These are PHP 8.2 WASM/FakeDb tests. They exercise real services/models/ledger,
  but do **not** demonstrate real MySQL concurrent lock behaviour, an applied
  migration on the deployment database, browser journeys or authenticated API
  compatibility.

## Sandbox acceptance checklist

- Approved account/access, correct HMAC canonicalization (including multipart
  string types and empty GET), NAIRA payout availability and sell create parsing.
- Real e-code variants, minimums, rate units, face-value currency, margin display,
  terms and totalAmount including any commission/fees.
- `uniqueIdentifier` and `referenceOrID` filter exactness and history visibility
  delay. A single filtered history result is accepted when the API omits echoing
  uniqueIdentifier; verify that filter guarantee before activation.
- Pending/completed/rejected, partial accepted card value, malformed response,
  invalid credentials, delayed history, API outage and timeout after acceptance.
- Exactly one local wallet credit, correct two-legged ledger, rollback on DB
  failures and concurrent admin/cron reconciliation on **native MySQL/MariaDB**.
- Provider rotation, disabled-new-sales behaviour, support/rejection procedure,
  customer ownership and rate-limit/anti-fraud controls.
- Verify the actual deployed web routes, permissions, CSRF handling and mobile
  usability using your staging browser before production activation.

## Validation completed in this workspace

- `GiftcardSellTest`: **54 tests, 447 assertions passed**.
- `PrestmitAdapterTest`: **31 tests, 179 assertions passed**.
- Full PHP suite: **1,987 tests, 21,763 assertions, zero failures, one WASM file-locking skip**. Provider deletion and cron control/scheduling regressions pass.
- PHP parser and JS tests passed; schema checks are rerun against the regenerated version-46 schema.
  Generated production SQL passes both its replay validator and the static
  table/column/foreign-key/index audit.
- The schema-only Python linter's three baseline false positives are repaired:
  it now registers ALTER ADD COLUMN actions before checking later foreign keys,
  and validates house rules on those columns. Four new Python regression tests
  prove valid ALTER/FK sequences pass and genuinely invalid columns still fail.
- The previously missing Reloadly `configured()` method is now implemented; its gift-card suite passes. No live or authenticated sandbox calls were made.

## Contract references

- https://documentation.prestmit.io/docs/creating-sell-gift-card-transaction
- https://documentation.prestmit.io/docs/about-sell-gift-card-rates
- https://documentation.prestmit.io/docs/tracking-sell-gift-card-transaction
- https://documentation.prestmit.io/reference/get_api-partners-v1-giftcard-trade-sell-history
- https://documentation.prestmit.io/reference/post_api-partners-v1-giftcard-trade-sell-create
- https://documentation.prestmit.io/docs/getting-started-api-keys-authentication

## Physical-card setup and retention

Apply migration 046 and explicitly set `PRESTMIT_PHYSICAL_ENABLED=true` only
after staging acceptance. Keep `PRESTMIT_SELL_ENABLED=true` and the configured
NGN provider as for digital sales. Disabling physical intake also stops physical
dispatches; already submitted sales still reconcile to avoid stranding money.

- PHP must permit at least 20 uploads, `upload_max_filesize=5M`,
  `post_max_size=24M` or greater, and a matching reverse-proxy body limit.
  Budget memory for encrypted image loading plus multipart assembly (256 MiB
  recommended; verify on the actual host). A too-large/partial request is never
  treated as a successful sale submission.
- Only JPEG/PNG, 1–20 images, at most **5 MiB each / 20 MiB total**, dimensions
  at most 12000×12000 and 40 megapixels. The 20 MiB aggregate cap is intentionally
  stricter than the provider's per-image allowance to bound memory/disk usage.
- PHP upload provenance and server-inspected image headers are required; original
  filenames, client MIME types and arbitrary local file paths are not trusted.
  Image-header validation is not antivirus/ownership verification.
- Images are AES-GCM encrypted via EncryptionService under
  `STORAGE_PATH/giftcard_images/`, with random keys, encrypted manifests,
  restrictive file permissions and deny guards. No card image URL is public.
- Staff need `giftcards.manage` to view an image; every successful view is
  audited and sent with no-store/private, nosniff, sandbox CSP and no-referrer.
  E-codes are still never revealed to staff.
- Dispatch decrypts only in process memory, sends fixed filenames in
  `attachments[]`, excludes binary attachment parts from the signed JSON, and
  never retries a create. Partial upload or decryption failure sends nothing.
- The sale cron removes files/manifests/codes 30 days after terminal settlement.
  Unresolved sales are not purged. Monitor storage growth and failed purge logs.
  A crash between filesystem upload and database persistence can leave encrypted
  orphan files; operators must reconcile those before deleting them. Backups
  need their own retention/encryption policy; deleting active files cannot erase
  old backups. Never rotate away the master key before resolving open sales.
- Review fraud, ownership and privacy procedures with the provider before opening
  physical intake. Test real HTTP uploads, missing/oversize files, auth isolation,
  staff permission denial, private-file denial and concurrent dispatch in staging.

See [signed webhook setup](module-prestmit-provider.md#signed-webhook-configuration)
for optional push-triggered reconciliation. Both polling jobs remain necessary.

Retention selection is not authoritative by itself: the worker locks each sale
and rechecks terminal status and its age against the same cutoff before purging.
A concurrent refresh protects the row; unresolved PENDING/SUBMITTING sales are
never purged merely because they are old. Purge counts are reported only after
the database commit succeeds.

## Quote acceptance and recovery-queue hardening

The final QUOTED-to-REVIEW write now also matches the customer, card form and
unexpired quote deadline. An initial browser/service check is not sufficient:
image intake and encryption may run past expiry. If acceptance is refused and rollback is confirmed before any commit attempt,
newly stored encrypted images are deleted and no manifest/fingerprint or image
count is attached to the quote. An uncertain commit preserves images instead;
check sale history before resubmitting. No provider submission or wallet credit
has happened at this stage.

`last_checked_at` records **attempted** reconciliation, including configuration
errors, HTTP outages and malformed responses. It is stamped before provider
lookup/history and outside the settlement transaction. Thus repeatedly failing
rows rotate behind other open sales in bounded cron batches; a rolled-back
settlement does not erase its place in the attempt queue. This is fair queue
progress, not an exclusive distributed polling claim or a provider retry limit.
Concurrent polls remain safe only through the existing sale lock/idempotent
ledger settlement. A failed attempt-marker write stops that reconciliation.

Settlement now checks transaction start and commit return values. It cannot
report success when either boundary fails. Offline fault-injection tests prove
that a refused start issues no credit and a refused, uncommitted transaction is
rolled back and can be retried once. A real network loss during COMMIT can have
an uncertain outcome: inspect the sale and idempotent ledger reference after
reconnecting, never manually reset state or issue a second credit.

A filesystem deletion cannot be rolled back with SQL. A partial physical-image
purge therefore keeps its encrypted manifest when cleanup fails. A regression
test deletes one image, fails the next operation and verifies that a later purge
removes the remaining file, clears the manifest, and records one completed purge
without touching financial history. Process-death orphan files still require
operator review; no unreferenced-file sweep was added.

## Audit-write failures and ambiguous local commits

An audit write returning `false` is now treated as a failure, just like an
exception. The sell workflow must not silently accept unaudited sensitive
operations:

- Digital/physical submission and local REVIEW rejection commit their state
  changes and audit in the same database transaction. A refused audit rolls
  them back; code fingerprints and image counts do not linger on a refused
  submission. Cleanup of newly written images follows confirmed rollback only.
- The REVIEW-to-SUBMITTING claim and `giftcard.sale.dispatched` audit are committed
  together **before** sending any code or attachment upstream. This event is an
  audited dispatch intent, not proof that Prestmit received or accepted it. A
  refused audit rolls the claim back to REVIEW, allowing a safe later attempt.
- False or thrown credit-audit failures roll settlement back with its ledger
  and sale state. Image access returns no bytes unless its access audit succeeds.
- Purge writes its audit inside the sale-row transaction before deleting files.
  If the audit is refused, the physical files are untouched. If a later deletion
  fails, SQL rolls back the audit/manifest update; the existing partial-delete
  retry behaviour still applies.

If COMMIT returns false or throws, it may have reached the database even if its
acknowledgement was lost. The service reports failure, does not dispatch, and
does not delete possibly attached images. A successfully committed SUBMITTING
claim must **not** be reset or retried blindly after that error: reconnect,
inspect its audit/reference, and reconcile with the provider. A successfully
committed physical submission remains REVIEW and duplicate submission does not
ingest files again. If no submission committed, retained encrypted orphans need
reference-based operator cleanup; a false commit return alone is not permission
to delete them. Failed cleanup is logged using the numeric sale ID only.

Eleven additional regressions cover false/throwing audit results across these
paths, plus commits that persisted but lost their acknowledgement and commits
refused before persistence. These are deterministic database doubles, not proof
of native MySQL network-failure behaviour. The audit change does not add a new
migration, expose card codes in logs, or enable any bank transfer feature.
