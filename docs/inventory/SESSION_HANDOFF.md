# IMS Session Handoff — July 20, 2026

> **Read this first when resuming IMS work.** Supersedes the May 5 planning-phase
> handoff. The IMS outbound (stock-out) roadmap is now **complete end-to-end**
> (Phases A–E). This doc is the map for making edits.

Work spans **two repos**, both on branch `feature/ims`:
- **Backend** — `cedibites_api/` (Laravel 12, PostgreSQL). API served at `/v1`.
- **Frontend** — `cedibites/` (Next.js 16, React 19). Inventory portal at `/inventory/*`.

Everything below is **committed** on `feature/ims` (frontend HEAD `5994f7a`,
backend HEAD `4a64f81`) but **not pushed**.

---

## 1. The spirit (why this exists)

From the founder's transcripts (`CBIMS.txt` / `CBIMS 2.txt`):

> *"Inventory management is basically like accounting. Whatever comes in, whatever
> comes out must cancel out. Where there are discrepancies we allow some within a
> threshold, then we cancel it out — another cycle begins."*

Every phase serves this: make **every movement tracked** (transfers, requisitions,
branch-level sale deductions, daily counts) so the books can be **reconciled to
zero** periodically. Keep it **simple enough for a non-technical branch operator**
("the Branch Manager Test"). Mother/central kitchen = warehouse; branches =
satellite kitchens; the mother feeds the branches.

---

## 2. How to run & test

All three servers are typically already running. To start them fresh:

```bash
# Backend API  (from cedibites_api/)
php artisan serve                    # http://localhost:8000  (routes under /v1)
php artisan reverb:start             # realtime, port 8080  (optional)
# php artisan queue:work             # only if broadcasting is queued (it's ShouldBroadcastNow → not required)

# Frontend  (from cedibites/)
npm run dev                          # http://localhost:3000
```

Frontend env (`cedibites/.env.local`): `NEXT_PUBLIC_API_URL=http://localhost:8000/v1`,
`NEXT_PUBLIC_IMS_MOCK=false` (must be false; it's a build-time var — restart dev
server if you change it).

**Test accounts** (all password `password`, log in at `/staff/login`):
| Role | Email | Can do |
|------|-------|--------|
| Warehouse Manager | `warehouse@cedibites.test` | everything IMS incl. reconciliation/adjustments |
| Purchasing Clerk | `purchasing@cedibites.test` | POs + receipts |
| Admin | `admin@cedibites.com` | full business + PO approvals |

After login the WM lands on `/inventory/dashboard`. Sidebar → **Transfers,
Requisitions, Daily Closing, Reconciliation** (+ Purchasing, Catalog, Configure).

**Smoke-test the reconciliation loop (Phase E), the newest work:**
1. `/inventory/reconciliation` → pick a location → **Open reconciliation**.
2. Enter counts (or "Match all to system", then tweak one item to create a variance).
3. **Post & reset** → confirm. Balances are corrected; cycle closes; net variance shown.
4. Re-open a cycle → the snapshot reflects the corrected balances ("new cycle begins").

---

## 3. What was built (Phases A–E)

The stock-out flow: **PO/Purchase → warehouse stock → (Requisition→)Transfer →
branch stock → Sale deducts branch → Daily count → Reconciliation trues-up.**

### Phase A — Transfers frontend
Physical stock movement (mother ⇄ satellite). Lifecycle
`draft→submitted→approved→sent→received→closed`, `sent↘disputed→closed_disputed`,
`(draft|submitted|approved)→cancelled`. Stock leaves source at **sent** (FEFO),
arrives at dest on **receive**; short receipt → dispute → corrective transfer.
- FE: `app/inventory/transfers/**`, `lib/api/services/inventory/transfers.service.ts`,
  `lib/api/hooks/inventory/useTransfers.ts`, `_components/TransferStatusBadge.tsx`.
- BE (pre-existing + broadcast added): `app/Domain/Inventory/Transfers/TransferService.php`,
  `TransferController`, `TransferBroadcastEvent`.

### Phase B — Requisitions (full-stack)
The request layer in front of transfers. `draft→submitted→approved→fulfilled`,
`↘rejected`. **Approving auto-spawns a draft transfer** (warehouse→branch) and, when
that transfer is **received in full**, the requisition **auto-flips to fulfilled**
(the coupling lives in `TransferService::receive()` → `fulfilRequisition()`; the
link propagates onto corrective transfers). MVP = warehouse→branch only.
- BE: `app/Domain/Inventory/Requisitions/RequisitionService.php`, `RequisitionController`,
  `Requisition`/`RequisitionLine` models, `RequisitionResource`, migrations
  `..._create_inventory_requisitions_table` (+ lines, + `requisition_id` on transfers).
- FE: `app/inventory/requisitions/**`, `requisitions.service.ts`, `useRequisitions.ts`.

### Phase C — Sales deduction re-pointed to the branch (backend-only)
`RecipeDeductionService::resolveDeductionLocation()` now deducts a paid order's
BOM ingredients from the **order's branch** inventory location
(`inventory_locations.branch_id = order.branch_id`), **falling back to the
warehouse** when a branch has no location mapped (logged). Refunds + negative-stock
alerts follow to the branch automatically.

### Phase D — Daily Closing (full-stack)
Mandatory end-of-day count → variance. `open→completed`. Opening snapshots expected
qty from the ledger; operator counts; completing requires **every** line counted.
14-day **coverage strip flags missed days**. Does **not** adjust stock (that's Phase E).
- BE: `app/Domain/Inventory/Closing/DailyClosingService.php` (+ `calendar()`), controller,
  `DailyClosing`/`DailyClosingLine`, migrations.
- FE: `app/inventory/daily-closing/**`, `dailyClosings.service.ts`, `useDailyClosings.ts`.

### Phase E — Reconciliation (full-stack) — the loop closes
Stock-take that **posts adjustments and resets the books**. `open→closed`. Opening
snapshots system qty + weighted cost; operator counts everything; **posting writes a
`cycle_adjustment` movement per non-zero variance** (via `MovementPostingEngine`) so
the ledger equals the count, then closes. One open cycle per location. Variances
worth > **₵500** (flat const) are flagged (red flag, still reconciled). Gated to the
**warehouse manager** (`inventory.reconciliation.adjust`) — *"he would do the adjustments."*
- BE: `app/Domain/Inventory/Reconciliation/ReconciliationService.php`, `ReconciliationController`,
  `ReconciliationCycle`/`ReconciliationLine`, `ReconciliationCycleResource`, migrations
  `2026_07_20_100001/100002_*`.
- FE: `app/inventory/reconciliation/**`, `reconciliations.service.ts`, `useReconciliations.ts`.

---

## 4. Patterns to follow when editing (keep it consistent)

- **Types are the contract.** `cedibites/types/inventory.ts` mirrors each backend
  `*Resource` exactly. Backend resources return **actor NAME strings** (not objects),
  nested `{id,name,type}` locations, and per-stage ISO timestamps. When a backend
  field changes, update the type first, then services/components. (The original
  speculative types were wrong — always re-derive from the resource.)
- **API layer:** `lib/api/services/inventory/*.service.ts` (thin axios wrappers,
  `extractData` unwraps `{data}`) → `lib/api/hooks/inventory/use*.ts` (React Query;
  query key `['inventory', <domain>]`; mutations invalidate that key).
- **Pages:** `app/inventory/<domain>/page.tsx` (server wrapper) → `_components/*Page.tsx`
  (client). Detail = `[id]/page.tsx`. Shared UI from `app/inventory/_components`
  (`PageHeader`, `DataTable`, `FilterBar`, `FormField/TextInput/Select/Textarea/PrimaryButton`,
  `InventoryModal`, `*StatusBadge`). Permission gating via `useStaffAuth().can('perm.string')`.
- **Backend writes:** all go through a domain **Service** in `app/Domain/Inventory/<Domain>/`;
  controllers validate + call the service + dispatch a `*BroadcastEvent` + return a Resource.
  The **only** ledger writer is `MovementPostingEngine::post()` (idempotent via
  `idempotency_key`). Domain errors throw `InventoryException` → controller `guard()` → 422.
- **Realtime:** `*BroadcastEvent` (ShouldBroadcastNow, channel `inventory.<domain>`,
  event `.<domain>.updated`) + `routes/channels.php` auth (gated `view_inventory_catalog`)
  + FE `use*Realtime.ts` invalidates the query key.
- **Permissions** live in `app/Enums/Permission.php`, granted in `database/seeders/RoleSeeder.php`,
  registered by `PermissionSeeder`. Gate reads with `view_inventory_catalog`.
- **Reference numbers:** `ReferenceGenerator` → `PREFIX-YYMMDD-NNN` (TRF/REQ/PO/RCP/PROD).
- **Tests:** `tests/Feature/Inventory/*Test.php` (Pest). Run `php artisan test tests/Feature/Inventory`
  → **53 passing**. Note the test DB is **sqlite :memory:** — `date` columns store a full
  datetime string there, so **query dates with `whereDate()`**, never `where()`/`whereBetween()`.

Verify a FE change compiles: `npx tsc --noEmit` (ignore the stale `.next/…/validator.ts`
error) and curl the route on :3000 for HTTP 200.

---

## 5. Known gaps / candidate next work

- **Inventory dashboard is mock-backed on the BACKEND** — no `/inventory/dashboard/stats`
  or alerts read endpoint; FE `dashboard.service.ts` expects them, stat cards silently
  don't render. Building these + surfacing `inventory_alerts` (negative-stock, over-threshold)
  is the highest-value next step.
- No **IMS settings table** — thresholds are constants (reconciliation ₵500). A
  per-location settings table would let thresholds be configured; IMS Settings
  "Assign role" is still a stub.
- **Inter-branch** transfers/requisitions deferred (warehouse→branch only for MVP).
  The transcript wants branch→branch with the source branch's manager approving.
- **Wastage** flow: schema/threshold discussed but no full build; return-to-warehouse
  evidence flow for disputed wastage not implemented.
- No production void/reversal; no near-expiry report **page** (endpoint exists);
  no Edit-Item form; no delete/deactivate for catalog entities; recipe editor is
  global-only UI; no unit conversion (recipe qty assumed in item base unit).
- **Pre-existing:** unauthenticated API requests to IMS routes 500 instead of 401
  (auth-exception not rendered as JSON) — cosmetic; authenticated use is fine.
- **Beyond IMS:** the founder's next big (separate) build is the **finance/accounting
  rollup** — cost-to-run vs revenue vs taxes/salaries, service-charge separation, CEO
  dashboard — *"once we get the inventory money right."* Out of scope for IMS.

---

## 6. Locked decisions (context for edits)

- Requisition approve → **auto-spawn transfer + auto-fulfill on receipt**; **warehouse→branch only** (2026-07-19).
- Reconciliation = **standalone cycle** (architecture §6.5), posts `cycle_adjustment`, resets to zero; WM-only.
- Disputed transfers are **immutable**; reconciled by a NEW corrective transfer (`parent_transfer_id`).
- Weighted-average costing; FEFO/expiry batches; recipes keyed per `menu_item_option`, global default + per-branch override (full replace).
- Wastage/variance red-flag threshold default **₵500**.

See `docs/inventory/architecture.md` for the full ERD, engines, and state machines.
