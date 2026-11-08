# Tenant POS

Phases 1–8: Laravel 13 REST API and Vue 3 SPA with Sanctum session authentication,
Pinia, TypeScript, Tailwind 4, PostgreSQL tenancy, and automated checks.
Resolved versions are pinned in composer.lock and package-lock.json.

## Start locally

Requires Docker Compose and Node 22. Run from this directory:

```sh
cp .env.example .env
docker compose build
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm ci
npm run build
docker compose run --rm app php artisan pos:create-user owner@example.com "Shop Owner"
docker compose up -d
```

Open http://localhost:8000, sign in, then create and select a business.
The user creation command prompts for a password without printing it.
For frontend development, run `npm run dev` while Laravel is running.
If this workspace requires a custom proxy CA, build with:
`docker build --secret id=proxy_ca,src="$CODEX_PROXY_CERT" -t pos-app .`

The PostgreSQL initialization script runs on the first creation of the database
volume. Existing databases need an administrator to apply docker/init.sql.
The default application login is pos_app (NOBYPASSRLS); migrations use pos.
Credentials in the example are for local development only.

## Implemented boundaries

- Sanctum cookie sessions, CSRF protection, login throttling, session rotation,
  logout invalidation, and disabled-user checks.
- Global users; tenant memberships with one role per business.
- Seeded roles with named permissions in JSON. This intentionally keeps static
  Phase 1 permissions simple; custom permission editing is not exposed.
- Atomic tenant provisioning creates its owner membership; tenant updates require
  the tenant.manage permission. Request-supplied role and tenant ownership fields
  are ignored by provisioning.
- Membership RLS restricts reads and writes to the authenticated identity, using
  transaction-local context. Missing identity returns no memberships.
- Tenant records are accessed through membership checks and policies. RLS covers memberships and the category, product, and warehouse tables.
  Catalog requests set transaction-local tenant context after checking membership.
  Products reference categories through a composite tenant/category foreign key.
- Tenant selection is explicit per URL and re-authorized on every request.
- Vue restores the cookie session and keeps no credentials in browser storage.

## API

Obtain GET /sanctum/csrf-cookie before login. Send cookies and X-XSRF-TOKEN.
The SPA uses the same origin, avoiding cross-origin cookie configuration.

| Method | Path | Purpose |
|---|---|---|
| POST | /api/v1/login | Authenticate |
| DELETE | /api/v1/session | Sign out |
| GET | /api/v1/me | Current user |
| GET | /api/v1/tenants | Active memberships |
| POST | /api/v1/tenants | Provision a business |
| GET | /api/v1/tenants/{id}/context | Authorized tenant and role |
| PATCH | /api/v1/tenants/{id} | Admin updates business name |

Initial provisioning supports USD, EUR, GBP, and SGD. The UI starts with USD/UTC.
User invitations, password recovery, deployment hardening and operational integration are later
features. There is no public user-registration endpoint.

## Validation

```sh
# Use a separate disposable PostgreSQL database, never a business database.
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=pos_test \
DB_USERNAME=postgres DB_PASSWORD=testing vendor/bin/phpunit
npm run build
```

Tests require an administrator on the disposable database to create a temporary
restricted role for the RLS probe. The application does not require that privilege.
Validated: 50 tests / 329 assertions on PostgreSQL 17 and PHP 8.3, plus Vue TypeScript
checking and production build. PHPUnit disables CSRF verification by framework
convention; these tests do not establish end-to-end browser CSRF behavior.
CI repeats backend tests and frontend build. Use this directory as repository root.

## Deployment scope

This is the Phase 8 foundation, not the completed production POS. Before deployment,
configure TLS, secure session cookies, APP_DEBUG=false, managed secrets, backups,
and restricted migration credentials. File sessions and synchronous queues are
local defaults; use shared session storage and queue workers when scaling.
No database credentials or application encryption key are committed.

## Phase 2 catalog

For an existing Phase 1 installation:

```sh
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm run build
```

After selecting a business, use Products, Categories, or Warehouses. Admins and
Inventory Managers can add and edit records. Cashiers can search and read them.
Uncheck Active in the edit form to archive a record; records are never hard-deleted.
Products support optional categories and barcodes, units, integer prices in the
business currency's minor units, and inventory tracking flags. Stock quantities and movements are available in the Inventory panel.

Each resource supports GET/POST `/api/v1/tenants/{tenant}/{resource}` and GET/PATCH
`/api/v1/tenants/{tenant}/{resource}/{id}` where resource is products, categories,
or warehouses. Lists use 25-row pagination, `q` search and optional `active=0|1`.
Product search matches names or exact SKU/barcode. Identifiers are case-sensitive
and unique within each tenant. Concurrent identifier collisions return HTTP 409;
ordinary validation failures return HTTP 422. Product-category links cannot cross
tenants even when written directly to the database.

The category hierarchy, advanced pricing/tax configuration, and product cost
accounting are not part of this first catalog slice. The offline register has a Chromium interaction test; catalog screens currently have type/build coverage.

## Phase 3 inventory

Apply migrations with the migration account and rebuild the frontend:

```sh
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm run build
```

The Inventory panel shows balances and movement history with warehouse filters.
Admins and Inventory Managers can record opening stock, positive/negative
adjustments, and immediate single-product transfers. A reason is required.
Refresh the panel after creating products or warehouses to reload the selectors.
Cashiers can read inventory but cannot adjust it.

- GET `/api/v1/tenants/{tenant}/inventory/stocks`
- GET `/api/v1/tenants/{tenant}/inventory/movements`
- POST `/api/v1/tenants/{tenant}/inventory/operations`

Operation fields: operation_id (client UUID), kind (adjustment or transfer),
product_id, warehouse_id, quantity (decimal STRING, up to four decimal places),
reason, and destination_id for transfers. Transfers require positive quantities;
adjustments require nonzero quantities. Maximum magnitude per operation is
999999999.9999. Archived/untracked products and inactive warehouses are rejected.

All quantities use NUMERIC(18,4). Stock rows are inserted safely and locked in
warehouse order. Insufficient available stock rolls back the entire operation.
A transfer creates two ledger entries referencing one immutable operation;
there is no shipping/in-transit workflow in this phase. History is protected
against UPDATE/DELETE by database triggers. Corrections require new adjustments.
The reserved column is prepared for sales workflows and remains zero here.

Operation UUIDs are scoped to a tenant. Exact retries return the original result;
changed payloads or actors return 409. The browser retains uncertain commands
in memory for retry: keep the page open after a network error. Durable offline
recovery belongs to Phase 5. The API provides idempotency across client restarts
when the same operation UUID and payload are supplied.

The integration suite covers decimal reconciliation, retry deduplication,
insufficient-stock rollback, permissions, invalid precision, foreign warehouses,
and immutable history. `php tests/Concurrency/inventory.php` additionally runs
two independent processes competing to debit the same stock; it requires an
administrator connection to a disposable database whose name ends in `_test`.
CI runs both suites. Inventory browser interaction tests remain outstanding.

## Phase 4 online cash checkout

Run migrations using the migration account and rebuild the SPA:

```sh
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm run build
```

Admins and Cashiers see Cash checkout after selecting a business. Choose a
warehouse, search products by name/SKU/barcode, add quantities, and enter cash
received in minor units. The current supported currencies all use two decimal
places. Catalog search returns the first 25 matches; narrow the search for more
specific products. Recent receipts shows the latest 25 accessible sales.

API:
- POST `/api/v1/tenants/{tenant}/orders`
- GET `/api/v1/tenants/{tenant}/orders`
- GET `/api/v1/tenants/{tenant}/orders/{id}`

Checkout accepts client_order_id (UUID), warehouse_id, cash_tendered_minor
(integer), and up to 100 distinct items. Each item contains product_id,
quantity (positive decimal STRING, up to four decimal places and six integer
digits), and expected_price_minor. The server loads current prices and rounds
each quantity × price to the nearest minor unit, half up, using PostgreSQL
NUMERIC. It sums rounded lines. The total and cash tender are capped at
999999999999 minor units. Prices that changed since selection produce 422;
refresh cart prices and review before resubmitting. Client totals are ignored.

This phase applies no discounts or tax. It supports completed cash sales only;
card payments, split tender, refunds, and configurable tax rules are later work.
Do not treat these receipts as jurisdiction-specific tax invoices.

Checkout creates the order, historical item snapshots, cash payment, and stock
ledger entries atomically. Tracked products require enough unreserved stock;
untracked products do not consume stock. Products and warehouses must be active
and belong to the business. Stock locks follow product ID order. Any failed line
rolls back the complete sale. Receipts and payment rows cannot be edited/deleted.
Receipt numbers use a tenant-unique UUID format rather than a gapless sequence.

Cashiers may read only their own sales; Admins may read all tenant sales.
Inventory Managers cannot create or read sales. Sales tables have tenant RLS and
composite foreign keys. The stock operation references its receipt through the
operation UUID and reason; a sale produces one movement for each tracked item.

Exact retries return the original receipt even if product prices changed after
commit. Reusing an order ID for a different payload or actor returns 409. Pending
commands are saved in sessionStorage, partitioned by user and tenant, before
submission and restored on tab reload. They contain no authentication tokens.
Keep the tab open for recovery; closing it may discard sessionStorage. This is
online retry recovery, not the durable offline engine planned for Phase 5.

Validation: 21 integration tests / 104 assertions, Vue type checking and build,
and `php tests/Concurrency/checkout.php`. The concurrency test uses independent
processes competing to sell six units each from ten: exactly one sale/payment
commits, one is rejected, and the stock balance and ledger both equal four.
CI runs the test on the disposable PostgreSQL database. The offline browser workflow is covered in Phase 5; print-layout tests remain outstanding.

## Phase 5 offline register and synchronization

```sh
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm ci
npm run build
```

Serve the production build over HTTPS or localhost. The generated service worker
pre-caches the root application shell and hashed assets. API responses and session
credentials are never put in Cache Storage. Development Vite mode does not install
the worker. After a new deployment, close old tabs to let the waiting worker activate.

1. Sign in, select a tenant, and choose a warehouse in Offline register.
2. Click Prepare / refresh offline catalog. This saves a device/user/tenant/warehouse
   lease and a full catalog snapshot in IndexedDB. The server retains the same snapshot.
3. Disconnect and use the offline register. Completing a cash sale first commits an
   immutable command and local receipt to IndexedDB, then clears the cart. A storage
   failure preserves the cart and does not show completion.
4. Reconnect. Sync runs on startup, reconnect, manual request, and a bounded retry
   timer with exponential backoff. Retryable results keep their original IDs.
5. Inspect local receipt status. Admins can review expired/revoked lease sales with
   an approval/rejection note. Approved sales create the normal receipt and ledger.

Authorization lasts eight hours. A cashier cannot start a local sale after that
expiry. Submission after expiry or with revoked authorization requires Admin review;
client time is evidence, not proof of authorization. Fresh Sanctum authentication
and current active membership are required to synchronize. A suspended user retains
local evidence but cannot submit it until authorized again. Receipt export is available
for support/reconciliation. Offline workspace restoration assumes a trusted POS device;
server-side authorization is always rechecked on reconnect.

The catalog limit is 5,000 products. Refresh currently downloads a complete snapshot,
including the removal of archived products; a cursor-based incremental feed is a
future optimization. Existing queued commands retain their original lease/snapshot.
Prices and product names come from that server-held historical snapshot. Cash and
quantities are validated; totals are recomputed on the server using exact decimals.
The offline register supports the same cash-only, no-tax/no-discount policy as Phase 4.

Batch sync accepts at most 50 commands. Each command has its own database transaction
with transaction-local identity and tenant context. The result is accepted,
already_processed, requires_review, rejected, or retryable with a stable code.
A lease/device/membership mismatch is rejected. A reused operation UUID with changed
payload is rejected. Results and sale effects commit together. Responses lost after
commit can be retried without duplicate sales or stock movements.

Unlike online checkout, valid completed offline sales may make inventory negative.
The result flags negative_stock for reconciliation. Local stock estimates combine
the saved snapshot and subsequent local sales and cannot account for other devices.
They are never used as an authoritative availability guarantee.

Endpoints:
- POST `/api/v1/tenants/{tenant}/offline/prepare` (device_id, warehouse_id)
- POST `/api/v1/tenants/{tenant}/sync/orders` (orders array)
- GET `/api/v1/tenants/{tenant}/offline/reviews` (Admin, paginated)
- POST `/api/v1/tenants/{tenant}/offline/reviews/{id}` (Admin decision and note)
- DELETE `/api/v1/tenants/{tenant}/offline/leases/{id}` (Admin revocation)

Each sync command includes operation_id, lease_id, device_id, occurred_at,
cash_tendered_minor, and item product_id/quantity/expected_price_minor fields.
Rejected and reviewed receipts remain in local history; do not discard unsynced
site data. Browser storage persistence is requested but remains browser-controlled.

Validation: 26 backend tests / 138 assertions; frontend types/build; Chromium
end-to-end test with normal CSRF protection. The browser test disconnects, reloads,
records a sale, reloads again, reconnects, loses the first committed sync response,
and retries to recover exactly one server order. It also simulates a storage-write
failure to ensure the cart is retained. CI includes this scenario.

For local browser tests, use a disposable PostgreSQL database ending in `_test`,
run `php tests/Browser/seed.php`, and serve the built application at localhost:8880
with APP_ENV=local, a test APP_KEY, file sessions/cache, and
SANCTUM_STATEFUL_DOMAINS=localhost:8880. Run `npx playwright install chromium` then
`npx playwright test`. Set CHROMIUM_PATH to use an installed Chromium executable.

## Phase 6 real-time coordination

Install the updated dependencies, rebuild PHP for PCNTL, and apply migrations.
Copy the Reverb settings from `.env.example` into an existing `.env` before starting.

```sh
docker compose build
docker compose run --rm app composer install --no-interaction
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm ci
npm run build
docker compose up -d app reverb worker scheduler
```

The API and worker use `REVERB_HOST=reverb` inside Compose. Browsers use
`VITE_REVERB_HOST=localhost` and port 8080. Vite values are embedded during build;
rebuild when changing them. The example credentials are for local development.
Use deployment secrets, TLS/WSS, and explicit allowed origins in production.
The scheduler can take up to a minute to begin its first dispatch cycle.

Catalog, inventory, and checkout views now refresh when another till changes
committed data. Open carts and edit forms are retained; checkout still verifies
current prices. Offline lease snapshots remain unchanged until explicitly refreshed.
The status indicator shows live connectivity or periodic recovery. Every 30 seconds,
and immediately on reconnect, the client checks the authenticated change feed.

Business transactions insert `outbox_events` and advance a per-tenant transactional
counter together. The counter serializes sequence assignment through commit, so
later committed changes cannot be hidden behind an already-advanced cursor.
Rolled-back business operations leave no event. Duplicate sale/stock retries do not
create another event. `GET /api/v1/tenants/{tenant}/changes` accepts `epoch` and
`after`, returns up to 100 events, and resets the client on a new/invalid epoch.
Initial loads and resets refresh authoritative API data before advancing the cursor.
The feed currently retains all events; no automatic retention/pruning is configured.

The scheduler runs `pos:dispatch-outbox` every ten seconds. It discovers committed
pending events and queues tenant-scoped delivery jobs on the database `realtime`
queue. Jobs set transaction-local tenant context, claim rows with SKIP LOCKED,
broadcast through Reverb, and acknowledge only successful sends. Failed sends retain
the event and retry with bounded exponential delay. A crash after broadcast but
before acknowledgement may deliver a duplicate; consumers treat notifications as
idempotent refresh hints. Polling recovers even when Reverb or the worker is down.

Private channels are `tenants.{tenant UUID}`; authorization uses Sanctum, active
membership, and active tenant checks at `/api/v1/broadcasting/auth`. Payloads contain
only an event UUID and sequence, with no product, customer, or financial records.
API reads always reauthorize. Existing subscriptions are disconnected when the next
periodic authorization check fails; revocation is not an immediate socket kick.
Client-originated Reverb events are disabled. Both stream tables use tenant RLS.

Operational checks:

```sh
docker compose logs --tail=50 worker scheduler reverb
docker compose exec app php artisan pos:dispatch-outbox
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:restart
```

Database queues and file cache/session storage keep local deployment simple.
Use shared cache/session storage and a single coordinated scheduler when scaling;
Reverb multi-node fanout requires its Redis scaling configuration. Monitor pending
event age, attempts, last_error, failed jobs, and queue depth. Do not delete pending
events to clear a delivery failure. Restart long-lived workers and Reverb after code
or configuration changes.

Phase 6 verification: 30 backend tests / 160 assertions, frontend type checking and
production build, and two Chromium scenarios: offline/lost-response recovery and
two separate tills receiving actual WebSocket frames and catching up after a
network interruption. CI starts Reverb, the queue worker, and scheduler for these tests.
Unique tenant jobs prevent repeated scheduler ticks from filling the queue with
identical work; the shared cache lock expires after ten minutes after a crash.

In managed environments with an outbound HTTP proxy, append only your own internal
Reverb service hostname to the worker's NO_PROXY configuration so local broadcasts
stay inside the container network. Preserve existing proxy settings and exemptions.

## Phase 7 Stripe test payments, refunds, and returns

```sh
docker compose run --rm app composer install --no-interaction
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm ci
npm run build
docker compose up -d app reverb worker scheduler
```

The worker must consume both `payments` and `realtime` queues (updated Compose does
this). Existing custom worker commands need `--queue=payments,realtime`.
Before a schema rollback, note that the migration refuses to remove persisted
payment-session or refund history. Back up the database before deployment.

Configure these locally in `.env`, never in frontend variables or source control:

```dotenv
STRIPE_SECRET_KEY=sk_test_your_test_key
STRIPE_WEBHOOK_SECRET=whsec_your_endpoint_secret
STRIPE_RETURN_URL=http://localhost:8000
```

Only Stripe test-mode secret keys and events are accepted. No Stripe credentials
are included. This implementation uses one configured merchant test account with
tenant-bound metadata; per-tenant Stripe Connect accounts are not implemented.
Clear cached configuration and restart workers after changing credentials.

For local webhook forwarding, authenticate your installed Stripe CLI to your own
test account, then run:

```sh
stripe listen --events checkout.session.completed,checkout.session.expired,refund.created,refund.updated,refund.failed --forward-to http://localhost:8000/api/v1/webhooks/stripe
```

Use the resulting signing secret for STRIPE_WEBHOOK_SECRET. The Stripe client pins
API version 2025-02-24.acacia; configure deployed test webhook endpoints accordingly.
HTTPS is required for a hosted endpoint. The browser uses Stripe's hosted checkout;
raw card details never pass through this application. The public test card number
4242 4242 4242 4242 can be used in your Stripe test checkout with a future expiry.

### Checkout behavior

Cash remains available without Stripe configuration. Configured tills can select
Stripe test card and optionally enter a cash portion. The server calculates the
quote, reserves inventory, and persists a payment session before calling Stripe.
External provider calls happen outside database transactions. Stripe creation uses
a stable provider idempotency key; local retries retain their client_order_id.

A signed paid webhook (or a server-to-server provider reconciliation) validates the
session, test mode, amount, currency, and tenant metadata before atomically creating
the receipt and the cash/card payment records. Redirecting back from Stripe does
not itself mark a sale paid. The browser restores the selected business after
return and shows card attempts with status/reconcile/cancel controls.
Cash received during a pending split payment must be returned if that attempt is
canceled. The cash ledger is recorded only when the card succeeds.

Reservations prevent competing online cash/card sales from consuming the same stock.
Confirmed provider expiry releases the reservation. A completed card payment uses
its historical quote. If a previously completed offline sale consumed that stock,
settlement still records the paid sale and its stock movement; reconcile resulting
negative balances rather than losing evidence of a collected payment.

Card totals are capped at 99,999,999 minor units. Card minimums are USD/EUR/SGD 50
and GBP 30 minor units. Supported currencies use two decimal places. Taxes, discounts,
multiple card tenders, disputes, and live-mode payments remain outside this phase.

The scheduler reconciles known pending provider sessions every minute and recovers
pending card refunds every ten seconds. Unknown results remain pending rather than
being treated as failed. A session whose provider creation result was lost can be
recovered through Check provider status, which retries the original provider key.
If a session has no provider ID and cannot be recovered after its expiry, operator
investigation in the Stripe test dashboard is required; its reservation is retained
conservatively. Do not release such stock until payment status is established.

### Refund rules

Only Admins have sales.refund permission. Open a receipt, enter the quantities to
refund and a reason, and choose Restock for returned saleable goods. Refunds go to
the original tenders, cash first then card. Restocking uses the original sale's
inventory-tracking snapshot, even if a product was subsequently archived or changed.
Returns go to the original warehouse. Damaged goods can be refunded without restock.

A locked original order serializes competing refund requests. Pending refunds count
against the remaining refundable quantity and payment amount. Cumulative allocation
rounding guarantees that fully refunding a line returns exactly its original rounded
total. Each request has a durable idempotency UUID. Original orders, items, payments,
and inventory history remain immutable; refunds append compensating records.

Cash refund entries are recorded immediately. Card refunds use queued Stripe calls
with stable idempotency keys. Returned inventory becomes available only after every
refund allocation succeeds. Pending/failed results remain visible on the receipt.
The UI identifies recovered requests so an operator can avoid handing cash out twice.

Failed card refunds require an Admin review note. The retry endpoint retrieves the
provider refund and confirms failed/canceled status before creating a new audited
attempt. Old attempt references are retained; late webhooks cannot roll the new
attempt backward. A successful duplicate webhook cannot create a second cash/card
entry or stock return. Refund creation is online only; offline refunds are not enabled.

### API and operations

- GET/POST `/api/v1/tenants/{tenant}/payments`
- POST `/api/v1/tenants/{tenant}/payments/{session}/reconcile`
- POST `/api/v1/tenants/{tenant}/payments/{session}/cancel`
- POST `/api/v1/webhooks/stripe` (raw signed body; no browser session required)
- GET `/api/v1/tenants/{tenant}/refunds` (Admin, paginated)
- POST `/api/v1/tenants/{tenant}/orders/{order}/refunds`
- POST `/api/v1/tenants/{tenant}/payments/refund-allocations/{allocation}/retry`

Card requests include client_order_id, warehouse_id, cash_applied_minor,
cash_tendered_minor, and the same validated item structure as cash checkout.
Refund requests include request_id, reason, and distinct items containing
order_item_id, decimal-string quantity, and boolean restock. Failed-refund retries
require a note. Cashiers can inspect/reconcile only their own payment attempts;
Admins can inspect tenant attempts. Financial rows have composite tenant foreign keys
and RLS; signed provider events are deduplicated in the same transaction as effects.

Manual recovery commands:

```sh
docker compose exec app php artisan pos:dispatch-refunds
docker compose exec app php artisan pos:reconcile-payments
docker compose exec app php artisan queue:failed
```

Validation includes 38 backend tests / 232 assertions, provider HTTP contract tests
and signed-webhook fixtures, concurrent refunds and checkout, frontend types/build,
and browser cash return, offline recovery, and real-time scenarios. Stripe API calls
in automated backend tests are mocked. An actual Stripe account round trip has not
been executed because test credentials have not been supplied in this environment.

## Phase 8 reports and audit trails

Apply the migration before serving this version, then rebuild the frontend and
restart long-lived workers:

```sh
docker compose run --rm -e DB_USERNAME=pos app php artisan migrate
npm run build
docker compose restart app worker scheduler
```

Admins see Reports & audit after selecting a business. Inventory Managers see the
low-stock report only. Cashiers cannot access management reports or audit history.
Permission checks are enforced by the API, not only by hidden UI controls.
Reports refresh through the existing tenant change feed and have a manual refresh.
Open audit history only when needed to avoid loading unnecessary records.

### Financial definitions

- Date filters include both local endpoint dates in the tenant's configured timezone.
  SQL boundaries are UTC start-inclusive/end-exclusive, including DST-short/long days.
  Requests are limited to 366 inclusive days. The default period is the last 30 days.
- Gross sales and receipt counts use completed_at. Offline sales enter reporting when
  accepted by the server; their untrusted client timestamps do not backdate revenue.
- Captures and paid refunds use the immutable transaction processed_at timestamp.
  A refund paid today against an older receipt is included in today's paid refunds.
- Net activity is period gross sales less period paid refunds. It is not profit or
  an accrual accounting statement. Tender net shows captures less paid refunds.
- Monetary totals are grouped by currency and serialized as integer minor-unit
  strings. The UI formats them using BigInt, preserving totals beyond JavaScript's
  safe Number range. No currency conversion or mixed-currency grand total is performed.
- Each sales/reconciliation/stock report is computed with one SQL statement and one
  PostgreSQL snapshot. Separate endpoint responses can reflect different snapshots;
  snapshot_at is provided for context.

Payment reconciliation selects receipts completed during the chosen period, compares
lifetime recorded captures against receipt totals, and checks paid/requested refund
limits. Outstanding refund amounts include reserved pending card allocations. The
first 25 inconsistent receipts are shown alongside the full discrepancy count.
Current pending card attempts and unfinished refunds are shown across all dates,
filtered by warehouse, up to 25 each. This compares internal ledgers and known
provider workflow states; it does not reconcile Stripe payouts, fees, or bank deposits.

### Low stock

Products have an editable nonnegative reorder_point (up to four decimal places).
The report shows active tracked products at active warehouses when on_hand-reserved
is at or below that point. Missing balance rows count as zero so unstocked products
are not silently omitted. Results are current, paginated at 25 product/location
pairs, and independent of the financial date range. Negative available balances
remain visible. Archived products/locations and untracked services are excluded.

### Audit history

Tenant business actions append audit_logs in the same transaction as their effects:
provisioning/settings, catalog changes, adjustments/transfers, completed sales,
payment reservation/settlement/expiry, refund requests/completion/provider updates,
refund retry authorization, and offline lease/review decisions. Replayed completed
commands do not create duplicate business-change audits. Failed business transactions
roll back their audit entries. Known-tenant HTTP 403 responses append access.denied
entries using the route template, with no request body or query string.

Each HTTP request receives a server-generated X-Request-ID. Audit entries include
that reference, a tenant-bound membership actor or provider/system source, the entity,
and explicit selected details. Catalog updates retain the locked row's before/after
values. Audit entries do not serialize login credentials, Stripe secrets, provider
payloads, card data, or arbitrary request bodies. User-entered business reasons and
review notes are retained deliberately. Avoid putting secrets in these free-text fields.
Actor IDs are membership references; automated completions identify their source.

Audit history is protected by tenant RLS, composite actor foreign keys, and an
UPDATE/DELETE rejection trigger. The migration refuses to roll back populated audit
history. No automatic audit deletion or historical backfill is performed: auditing
begins with this migration. These controls do not prevent a privileged database
administrator from changing the database; external archival/retention controls are
part of production operations. Denials before tenant resolution and global login
failures are outside this tenant audit table.

### API

- GET `/api/v1/tenants/{tenant}/reports/sales`
- GET `/api/v1/tenants/{tenant}/reports/reconciliation`
- GET `/api/v1/tenants/{tenant}/reports/low-stock`
- GET `/api/v1/tenants/{tenant}/reports/audit`

Sales and reconciliation accept from/to dates (YYYY-MM-DD) and optional warehouse_id.
Low stock accepts warehouse_id and page. Audit accepts from/to, page, exact action,
entity_id, and request_id, and intentionally spans all warehouses. Warehouse ownership
is validated. Audit details are returned as structured JSON. Sales and reconciliation
require Admin permissions; Inventory Managers can access only low stock.

Validation: 50 backend tests / 329 assertions, including local-midnight/DST boundaries,
older-receipt refunds, mixed currencies, large exact amounts, missing stock/reservations,
permission checks, audit rollback/immutability/RLS, and provider audit deduplication.
The browser reports scenario checks displayed sales/refund totals, reconciliation,
low stock, and filtered audit history. Existing offline, real-time, and refund browser
scenarios and all three concurrency scripts remain in CI.
