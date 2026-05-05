# CediBites Inventory Management System — Architecture v2

**Status:** Locked. Phase 0 not yet started.  
**Last updated:** May 5, 2026  
**Owner:** Inventory Auditor agent  
**Related:** [`ims-considerations.instructions.md`](../../.github/instructions/ims-considerations.instructions.md) · [`inventory-auditor-kb.md`](../agents/inventory-auditor-kb.md)

This is the formal architecture document for the CediBites IMS. It is the single source of truth for the data model, module layout, engines, events, and roadmap. The instruction file enforces guardrails; this document explains the system.

---

## 1. Goals

Build a tablet-first, multi-branch inventory management system that:

1. Runs alongside the existing CediBites platform without disrupting orders, POS, or analytics
2. Tracks every gram of stock from supplier purchase → warehouse → satellite kitchen → customer order
3. Auto-deducts ingredients on order completion, idempotently and out-of-band
4. Surfaces variance, dispute, wastage, and reorder signals to operators in real time
5. Stays simple enough for non-technical operators to use without training

## 2. Non-Goals

- Full general-ledger accounting (deferred post-MVP)
- POS modifications, order numbering changes, messaging routing changes (separate tickets)
- FIFO/LIFO costing (weighted-average for MVP)
- Multi-warehouse complexity beyond schema readiness (single warehouse for MVP)

---

## 3. Stack

- **Backend:** Laravel 12, PHP 8.4, MySQL 8, Redis (cache + queue), Sanctum, Spatie Permission, Spatie ActivityLog, Spatie MediaLibrary, Reverb
- **Frontend:** Next.js 16, React 19, TypeScript 5, TanStack Query v5, Tailwind CSS 4
- **No new dependencies** without explicit approval

---

## 4. Module Isolation Strategy

### 4.1 Backend

- Namespace: `app/Domain/Inventory/{Movements,Transfers,Recipes,Wastage,Counts,Reconciliation,Reports,Purchases,Locations,Items,Recipes}`
- Routes: `routes/inventory.php`, registered conditionally on `IMS_ENABLED`
- Middleware: `EnsureInventoryEnabled` blocks all IMS routes when flag is off
- Queue: dedicated `inventory` queue for all IMS jobs
- Service provider: `App\Domain\Inventory\InventoryServiceProvider`

### 4.2 Frontend

- Route group: `cedibites/app/inventory/` — own layout, sidebar, providers
- Lazy-loaded — IMS bundle never inflates POS / customer / staff bundles
- Hooks: `lib/api/hooks/inventory/`
- Services: `lib/api/services/inventory/`
- Types: `types/inventory.ts`
- Feature flag consumed from `/api/me/features`

### 4.3 Database

- All tables prefixed `inventory_`
- Zero ALTERs to existing tables
- FKs point OUT only — IMS may FK to `users`, `branches`, `menu_items`. Nothing FKs INTO `inventory_*`.
- All FKs use `restrict` on delete

### 4.4 Cross-domain coupling

- IMS subscribes to `OrderCompleted`, `OrderCancelled` events. Order code is unaware of IMS.
- IMS reads `menu_items` and `branches`. Never writes.
- Permissions registered additively via Spatie.

---

## 5. Data Model

### 5.1 Locations & Catalog

| Table                        | Purpose                                                                                                |
| ---------------------------- | ------------------------------------------------------------------------------------------------------ |
| `inventory_locations`        | Warehouses + satellite kitchens. `type` enum, optional `branch_id` FK                                  |
| `inventory_categories`       | Hierarchical item categories                                                                           |
| `inventory_units`            | kg, g, L, mL, piece, carton, etc.                                                                      |
| `inventory_unit_conversions` | from_unit_id, to_unit_id, factor                                                                       |
| `inventory_items`            | Master item list with `consumable`, `storage_type`, `reorder_level`, `min_threshold`, `expiry_tracked` |
| `inventory_suppliers`        | Supplier directory with contact + payment terms                                                        |
| `inventory_settings`         | Per-location key/value (e.g. `wastage_threshold_amount` default 500)                                   |

### 5.2 Recipes (BOMs)

| Table                          | Purpose                                                                                                              |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| `inventory_recipes`            | One row per `(menu_item_id, branch_id?)` combo. `status` enum (`draft`/`observation`/`locked`), `current_version_id` |
| `inventory_recipe_versions`    | Immutable version history of a recipe                                                                                |
| `inventory_recipe_ingredients` | Ingredient lines per version: `version_id`, `item_id`, `qty_per_portion`, `unit_id`                                  |

**Resolution rule on order completion:**

1. Look up recipe for `(menu_item_id, branch_id)` → if exists and `status = locked`, use it
2. Else look up global recipe `(menu_item_id, NULL)` → if exists and `status = locked`, use it
3. Else: log to `inventory_alerts` and skip deduction (do not block order)

**Override rule:** per-branch override is FULL REPLACE — no inheritance from global ingredient list.

### 5.3 The Ledger (core)

```sql
inventory_stock_movements (
  id BIGINT PK,                     -- snowflake/ULID
  item_id BIGINT FK,
  location_id BIGINT FK,
  quantity DECIMAL(14,4),           -- signed: +receipts, -issues
  movement_type ENUM(
    'purchase', 'transfer_in', 'transfer_out',
    'sale', 'wastage', 'count_adjustment',
    'cycle_adjustment', 'production', 'return',
    'reversal'
  ),
  reference_type VARCHAR,           -- polymorphic
  reference_id BIGINT,
  batch_id BIGINT NULL FK,
  unit_cost_at_time DECIMAL(14,4),
  user_id BIGINT FK,
  parent_movement_id BIGINT NULL,   -- for reversals
  idempotency_key VARCHAR UNIQUE,   -- prevents double-post
  occurred_at TIMESTAMP,
  created_at TIMESTAMP,
  INDEX (location_id, occurred_at),
  INDEX (item_id, occurred_at),
  INDEX (reference_type, reference_id),
  UNIQUE (idempotency_key)
)
```

```sql
inventory_stock_balances (
  item_id BIGINT,
  location_id BIGINT,
  quantity DECIMAL(14,4),
  weighted_avg_cost DECIMAL(14,4),
  last_movement_id BIGINT FK,
  updated_at TIMESTAMP,
  PRIMARY KEY (item_id, location_id)
)
```

**Invariants:**

- Movements are append-only. Never edit, never soft-delete.
- Reversals = new movements with opposite sign + `parent_movement_id` set.
- Every write wraps both tables in `DB::transaction()` with `lockForUpdate()` on the balance row.
- `idempotency_key` is set per business operation (e.g. `order_line_id`, `transfer_line_id`).

### 5.4 Workflow Tables

| Table                                 | Purpose                                                                                                              |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `inventory_purchases` + `_lines`      | Goods received from suppliers; drives weighted-avg cost recalc                                                       |
| `inventory_batches`                   | FEFO/expiry tracking per batch                                                                                       |
| `inventory_requisitions` + `_lines`   | Multi-line invoice-style stock requests; `purpose` (`opening`/`supplementary`); `source_type` (`warehouse`/`branch`) |
| `inventory_transfers` + `_lines`      | Physical stock movement; `parent_transfer_id` for corrective; `source_validation_overridden_by`                      |
| `inventory_dispute_resolutions`       | Permanent dispute history + evidence + resolution metadata                                                           |
| `inventory_wastage_events` + `_lines` | Wastage records; `approval_status`, `return_to_warehouse_transfer_id`                                                |
| `inventory_wastage_returns`           | Physical return-to-warehouse evidence linking                                                                        |
| `inventory_stock_counts` + `_lines`   | Periodic full physical inventory + variance                                                                          |
| `inventory_daily_closing_entries`     | **Mandatory** end-of-day per-item closing input; computed `expected_qty`, `actual_qty`, `variance`                   |
| `inventory_reconciliation_cycles`     | Manager-initiated reconciliation periods                                                                             |
| `inventory_production_logs`           | Kitchen-produced stock items (not sale-driven)                                                                       |
| `inventory_alerts`                    | Low-stock / near-expiry / variance / dispute / wastage / missed-closing alerts                                       |

---

## 6. State Machines

### 6.1 Requisition

```
draft → submitted → approved → fulfilled → closed
                  ↘ rejected
```

### 6.2 Transfer

```
draft → submitted → approved → sent → received → closed
                                    ↘ disputed → (corrective transfer) → closed_disputed
```

- Source-stock validation runs at `submitted` step. Deficit blocks unless `inventory.transfer.override_source_check` permission exercised (recorded).
- Disputed records are immutable. Resolution = NEW transfer with `parent_transfer_id` set.
- Inter-branch transfer approval: Branch B's manager approves; warehouse manager notified read-only.

### 6.3 Wastage

```
recorded → auto_accepted (if amount < ₵500 AND reason ≠ spoiled_from_warehouse)
        ↘ pending_approval → approved
                          ↘ awaiting_physical_return → approved
                                                    ↘ rejected
```

- Threshold configurable per location via `inventory_settings.wastage_threshold_amount`.

### 6.4 Daily Closing (Mandatory)

```
not_entered → entered → variance_calculated
not_entered → missed (flagged on variance report)
```

- Missed days never silently fabricate an `actual`. Next opening uses last known actual or expected as fallback, flagged.

### 6.5 Reconciliation Cycle

```
opened → counts_in_progress → variances_computed → adjustments_posted → closed
```

- Manager-initiated, NOT calendar-enforced.

---

## 7. Engines

Every complex behavior is encapsulated in a single-purpose Engine class under `app/Domain/Inventory/{SubDomain}/Engines/`. Each engine has one public method and a Pest unit test file.

| Engine                           | Responsibility                                                              |
| -------------------------------- | --------------------------------------------------------------------------- |
| `MovementPostingEngine`          | Atomically post movement + update balance under row lock                    |
| `TransferStockValidator`         | Pre-flight: source has enough stock                                         |
| `WastageApprovalEngine`          | Threshold + reason → approval routing                                       |
| `RecipeVersioningEngine`         | draft → observation → locked transitions + version snapshots                |
| `DailyClosingVarianceCalculator` | Compute expected from ledger, store variance vs actual                      |
| `CycleReconciliationEngine`      | Compute net variance for open cycle, post `cycle_adjustment`                |
| `DisputeResolutionService`       | Create corrective transfer linked to disputed parent (never edits parent)   |
| `StockLedgerReportService`       | Produce canonical-column ledger for date range × location × item(s)         |
| `WeightedAverageCostCalculator`  | Recalculate weighted-avg cost on each receipt                               |
| `ReorderSuggestionEngine`        | Nightly: items below `reorder_level` → emit alerts                          |
| `IngredientDeductionForOrderJob` | OrderCompleted listener → deduct ingredients per locked recipe (idempotent) |

---

## 8. Events & Real-Time

### Consumed (from Order domain)

- `OrderCompleted` — payload: `order_id`, `branch_id`, `lines[]` (`menu_item_id`, `quantity`)
- `OrderCancelled` (post-completion) — triggers compensating reversal

### Emitted

- `StockMovementCreated` — broadcast on `inventory.location.{id}` (Reverb)
- `LowStockDetected` — Notification + alert row
- `WastageThresholdExceeded` — Notification to warehouse manager
- `TransferDisputed` — Notification to warehouse manager
- `DailyClosingMissed` — flagged on variance report (no notification by default)

### Channels

- `inventory.location.{id}` — live movement stream for branch dashboards
- `inventory.alerts.{branch_id}` — alert stream for branch managers
- `inventory.warehouse.alerts` — alert stream for warehouse manager

---

## 9. Roles & Permissions

Registered additively via Spatie in Phase 0. See [`inventory-auditor-kb.md` §3](../agents/inventory-auditor-kb.md) for the full matrix.

Key roles introduced:

- **Purchasing Clerk** (new) — records supplier purchases, maintains supplier list
- **Warehouse Manager** (new) — second-tier oversight, transfer approvals, wastage approvals, reconciliation
- Existing roles (Branch Manager, Admin) gain new IMS permissions

---

## 10. Reports

All under `/inventory/reports`, NOT on dashboards.

| Report             | Description                                                                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Stock Ledger       | Canonical columns: Opening · Received · Transfers In · Transfers Out · Sales (BOM) · Wastage · Expected Closing · Actual Closing · Variance |
| Variance           | Expected vs actual per item per period                                                                                                      |
| Daily Closing      | Branch closing entries + variances + missed days                                                                                            |
| Wastage            | By branch, reason, threshold breaches                                                                                                       |
| Dispute            | Frequency by source location + resolution outcomes                                                                                          |
| Transfer           | All transfers with full status timeline                                                                                                     |
| Purchases          | By supplier, item, period                                                                                                                   |
| Cost-of-Sales      | Weighted-avg cost × BOM × sales                                                                                                             |
| Reorder Suggestion | Items below `reorder_level`                                                                                                                 |

**Dashboard widgets only:** alerts · low-stock count · pending requisitions · pending wastage approvals · today's transfers · today's wastage value vs threshold.

---

## 11. Performance & Scale

Target throughput: **≥ 1M movements/month** comfortably (current expected ~3M/year, 10× headroom).

Patterns:

- Hot-read of current balance: O(1) via `inventory_stock_balances` (~1ms)
- Concurrent deductions: `lockForUpdate()` on the balance row (narrow lock window)
- Idempotency: UNIQUE `idempotency_key` index
- Off-peak reconciliation: queued jobs on `inventory` queue
- Live updates: Reverb broadcast on `StockMovementCreated`
- Reports: composite indexes on `(location_id, occurred_at)`, `(item_id, occurred_at)`, `(reference_type, reference_id)`. Future: monthly partitioning.
- Read pressure: Laravel read-replica routing (reports → replica, writes → primary)
- Hot lists: Redis cache of low-stock per branch, invalidated on movement

Anti-patterns avoided:

- ❌ `SUM(quantity)` over ledger on every read
- ❌ Synchronous deduction inside order request lifecycle
- ❌ Wrapping order + inventory deduction in a single transaction
- ❌ Silently storing negative stock
- ❌ Soft-deleting movements

---

## 12. Branching, Flags & Deployment

- Long-lived `feature/ims` branch in both repos
- Frequent small PRs to `main`/`master` with feature flag OFF
- Feature flag: `IMS_ENABLED` env → `config('features.inventory.enabled')`
- Middleware `EnsureInventoryEnabled` blocks all IMS routes when off
- Frontend gates via `/api/me/features`
- Staging runs flag-ON against prod-snapshot DB
- Production flips flag only after UAT
- All migrations are additive; no `down()` ever drops existing tables

**DevOps gap to verify before cutting `feature/ims`:** confirm beta/main pipeline doesn't auto-merge in a way that defeats the flag strategy.

---

## 13. Roadmap

| Phase                            | Scope                                                                                                                                                                                                                                    |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **0 — Foundation**               | Module scaffold, feature flag, route group, permissions, locations table, items/categories/units/suppliers CRUD, basic admin UI, warehouse-portal three-section layout (Purchases / Supply / Inventory Accounting)                       |
| **1 — Movements core**           | Stock ledger + balances, purchases (receiving), manual adjustments, stock count + variance, daily closing entry workflow, cycle reconciliation engine, Stock Ledger report with canonical columns, dashboard widgets                     |
| **2 — Transfers & Requisitions** | Multi-line requisition form, source picker (warehouse/branch), source-stock validator, transfer status machine, dispute as immutable record + corrective-transfer flow, dispute KPI surfacing, inter-branch transfers, real-time updates |
| **3 — Recipes & Auto-Deduction** | Recipe CRUD with versioning + status machine, OrderCompleted listener + deduction job, wastage with ₵500 threshold + approval engine + return-to-warehouse evidence flow, alerts engine, FEFO/expiry UI, non-consumable item handling    |
| **4 — Reports & Polish**         | Full reports suite (9 reports), CSV export, CEO dashboard rollup, performance tuning                                                                                                                                                     |

---

## 14. Out of Scope (Explicit)

- POS phone-number compulsion
- Order serial vs order number refactor
- Service charge separation in admin reports
- SMS broadcast / Hubtel routing changes
- Full finance/accounting module

These are separate tickets, not part of the IMS initiative. Do not modify POS, order numbering, messaging templates, or finance modules under IMS work.

---

## 15. ERD (Textual)

```
inventory_locations ─┬─< inventory_stock_movements >─ inventory_items
                     │                              │
                     ├─< inventory_stock_balances >─┤
                     │                              │
                     ├─< inventory_daily_closing_entries
                     │
                     ├─< inventory_transfers (source) >─┐
                     │                                  │
                     └─< inventory_transfers (dest) ────┘
                                │
                                ├─< inventory_transfer_lines >─ inventory_items
                                ├─< inventory_dispute_resolutions
                                └─ parent_transfer_id ─→ inventory_transfers (self)

inventory_requisitions >─< inventory_requisition_lines >─ inventory_items

inventory_purchases >─< inventory_purchase_lines >─ inventory_items
       │                       │
       └─ inventory_suppliers  └─ inventory_batches

inventory_recipes ─→ menu_items (FK out, read-only)
       │
       ├─< inventory_recipe_versions
       │           │
       │           └─< inventory_recipe_ingredients >─ inventory_items
       └─ branch_id (nullable) ─→ branches (FK out)

inventory_wastage_events >─< inventory_wastage_lines >─ inventory_items
       │
       ├─ approval_status enum
       └─ inventory_wastage_returns ─→ inventory_transfers

inventory_stock_counts >─< inventory_stock_count_lines >─ inventory_items

inventory_reconciliation_cycles ─< (cycle_adjustment movements)

inventory_alerts ─→ (item_id, location_id, type)

inventory_settings ─→ location_id (key/value, e.g. wastage_threshold_amount)
```

---

## 16. References

- Workflow rules: [`ims-considerations.instructions.md`](../../.github/instructions/ims-considerations.instructions.md)
- Code-quality: [`code-quality.instructions.md`](../../.github/instructions/code-quality.instructions.md)
- Engineering practices: [`Engineering-practices.instructions.md`](../../.github/instructions/Engineering-practices.instructions.md)
- KB: [`inventory-auditor-kb.md`](../agents/inventory-auditor-kb.md)
- Agent: [`inventory-auditor.agent.md`](../../.github/agents/inventory-auditor.agent.md)
