# Inventory Auditor — Knowledge Base

**Owner agent:** Inventory Auditor  
**Last updated:** May 5, 2026  
**Status:** Initialized — Phase 0 not yet started

This is the persistent institutional memory for the IMS domain. Update on every meaningful change. Code is truth — if this KB conflicts with code, fix the KB.

---

## §1 IMS Architecture Map

**Status:** Designed, not yet implemented. See `cedibites_api/docs/inventory/architecture.md` for the full ERD and module layout.

### Locations

- `inventory_locations` — `id`, `name`, `type` enum (`warehouse`/`satellite`), `branch_id` (nullable FK→branches), `is_active`, timestamps.
- MVP: ONE warehouse row + N satellite rows (one per branch).

### Items & Catalog

- `inventory_categories` (hierarchical: parent_id)
- `inventory_units` + `inventory_unit_conversions`
- `inventory_items` (master) — fields include `consumable` (bool), `storage_type` enum, `reorder_level`, `min_threshold`, `expiry_tracked`, `default_supplier_id`
- `inventory_suppliers`

### Recipes (BOMs)

- `inventory_recipes` — `menu_item_id` FK, `branch_id` (nullable for global), `status` enum (`draft`/`observation`/`locked`), `current_version_id`
- `inventory_recipe_versions` — version history, immutable
- `inventory_recipe_ingredients` — per-version ingredient list with qty + unit
- **Override rule:** per-branch override is FULL REPLACE (no inheritance from global)

### Movements & Balances (the core)

- `inventory_stock_movements` — **immutable ledger.** Fields: `id`, `item_id`, `location_id`, `quantity` (signed), `movement_type` enum, `reference_type`/`reference_id` (polymorphic), `batch_id`, `unit_cost_at_time`, `user_id`, `occurred_at`, `idempotency_key` UNIQUE, `parent_movement_id` (for reversals)
- `inventory_stock_balances` — denormalized cache, composite PK (`item_id`, `location_id`), `quantity`, `weighted_avg_cost`, `last_movement_id`
- `inventory_batches` — for FEFO/expiry tracking

### Workflow Tables

- `inventory_purchases` + `_lines` — supplier deliveries to warehouse (drives weighted-avg cost)
- `inventory_requisitions` + `_lines` — multi-line invoice-style requests; `purpose` (`opening`/`supplementary`); `source_type` (`warehouse`/`branch`)
- `inventory_transfers` + `_lines` — physical movement; `parent_transfer_id` for corrective; `source_type`; `source_validation_overridden_by`
- `inventory_dispute_resolutions` — permanent dispute history + resolution metadata
- `inventory_wastage_events` + `_lines` — `approval_status` enum, `return_to_warehouse_transfer_id`
- `inventory_wastage_returns` — physical return-to-warehouse evidence
- `inventory_stock_counts` + `_lines` — periodic full physical inventory
- `inventory_daily_closing_entries` — mandatory end-of-day per-item closing input
- `inventory_reconciliation_cycles` — manager-initiated reconciliation periods
- `inventory_production_logs` — kitchen-produced stock items (not sale-driven)
- `inventory_alerts` — low-stock / near-expiry / variance / dispute / wastage alerts
- `inventory_settings` — per-location key/value (e.g. `wastage_threshold_amount` default 500)

---

## §2 Workflow State Machines

### Requisition

```
draft → submitted → approved → fulfilled → closed
                  ↘ rejected
```

### Transfer

```
draft → submitted → approved → sent → received → closed
                                    ↘ disputed → (corrective transfer created) → closed_disputed
```

- Disputed records are permanent. Corrective transfer FKs back via `parent_transfer_id`.
- Source-stock validation runs at `submitted` step.

### Wastage

```
recorded → auto_accepted (if < ₵500 AND reason ≠ spoiled_from_warehouse)
        ↘ pending_approval → approved
                          ↘ awaiting_physical_return → approved
                                                    ↘ rejected
```

### Daily Closing

```
not_entered → entered → variance_calculated
not_entered → missed (flagged on variance report)
```

### Reconciliation Cycle

```
opened (by warehouse manager) → counts_in_progress → variances_computed → adjustments_posted → closed
```

---

## §3 Permission Matrix

**Status:** To be registered by IAM Auditor in Phase 0.

| Permission                                 | Purchasing Clerk | Branch Manager                        | Warehouse Manager    | Admin |
| ------------------------------------------ | ---------------- | ------------------------------------- | -------------------- | ----- |
| `inventory.purchase.create`                | ✓                |                                       | ✓                    | ✓     |
| `inventory.purchase.view`                  | ✓                | view own branch costs only            | ✓                    | ✓     |
| `inventory.requisition.create`             |                  | ✓ (own branch)                        | ✓                    | ✓     |
| `inventory.requisition.approve`            |                  | ✓ (own branch, inter-branch incoming) | ✓ (warehouse-source) | ✓     |
| `inventory.transfer.create`                |                  | ✓                                     | ✓                    | ✓     |
| `inventory.transfer.send`                  |                  | ✓ (sending branch)                    | ✓                    | ✓     |
| `inventory.transfer.receive`               |                  | ✓ (receiving branch)                  | ✓                    | ✓     |
| `inventory.transfer.dispute`               |                  | ✓                                     | ✓                    | ✓     |
| `inventory.transfer.resolve_dispute`       |                  |                                       | ✓                    | ✓     |
| `inventory.transfer.override_source_check` |                  |                                       |                      | ✓     |
| `inventory.wastage.record`                 |                  | ✓                                     | ✓                    | ✓     |
| `inventory.wastage.approve`                |                  |                                       | ✓                    | ✓     |
| `inventory.daily_closing.enter`            |                  | ✓                                     | ✓                    | ✓     |
| `inventory.recipe.view`                    |                  | ✓                                     | ✓                    | ✓     |
| `inventory.recipe.edit_global`             |                  |                                       |                      | ✓     |
| `inventory.recipe.override_per_branch`     |                  |                                       |                      | ✓     |
| `inventory.recipe.lock`                    |                  |                                       |                      | ✓     |
| `inventory.reconciliation.open_cycle`      |                  |                                       | ✓                    | ✓     |
| `inventory.reconciliation.adjust`          |                  |                                       | ✓                    | ✓     |
| `inventory.report.view`                    | scoped           | scoped (own branch)                   | ✓                    | ✓     |
| `inventory.settings.manage`                |                  |                                       | ✓ (per-location)     | ✓     |

**Inter-branch transfer approval:** Branch B's manager approves requests from Branch A. Warehouse manager gets notification (read-only visibility), no veto.

---

## §4 Engine Registry

**Status:** All planned, none implemented yet.

| Engine                           | Single Purpose                                                              | Tests |
| -------------------------------- | --------------------------------------------------------------------------- | ----- |
| `MovementPostingEngine`          | Atomically post a movement + update balance under row lock                  | TBD   |
| `TransferStockValidator`         | Pre-flight: source has enough stock to fulfill                              | TBD   |
| `WastageApprovalEngine`          | Threshold + reason → auto_accepted vs pending_approval                      | TBD   |
| `RecipeVersioningEngine`         | Manage draft → observation → locked transitions + version snapshots         | TBD   |
| `DailyClosingVarianceCalculator` | Compute expected from ledger, store variance vs actual                      | TBD   |
| `CycleReconciliationEngine`      | Compute net variance for open cycle, post `cycle_adjustment` movements      | TBD   |
| `DisputeResolutionService`       | Create corrective transfer linked to disputed parent (never edits parent)   | TBD   |
| `StockLedgerReportService`       | Produce canonical-column ledger for date range × location × item(s)         | TBD   |
| `WeightedAverageCostCalculator`  | Recalculate weighted-avg cost on each receipt                               | TBD   |
| `ReorderSuggestionEngine`        | Nightly: items below `reorder_level` → emit alerts                          | TBD   |
| `IngredientDeductionForOrderJob` | OrderCompleted listener → deduct ingredients per locked recipe (idempotent) | TBD   |

---

## §5 Finding Registry

### §5.1 Open Findings

_None yet — module not implemented._

### §5.2 Resolved Findings

_None yet._

### §5.3 Accepted Risks

_None yet._

---

## §6 Decision Log

### 2026-05-05 — Initial Architecture Locked (v2)

All decisions captured in `cedibites_api/.github/instructions/ims-considerations.instructions.md` §1–§2. Key decisions:

1. Same-domain dedicated portal (POS pattern)
2. Separate `inventory_locations` table
3. Single warehouse MVP, schema supports N
4. Append-only ledger + denormalized balances
5. Deduction on `OrderCompleted` only
6. Recipes: global + per-branch override (Admin only, full replace, no inheritance)
7. Weighted-average costing for MVP
8. Batch/FEFO schema present, UI Phase 3
9. Tablet-first
10. Stack: Laravel 12 + MySQL + Sanctum + Reverb + Next.js 16 — no Supabase
11. Wastage threshold ₵500 default, configurable per location
12. Inter-branch transfer approval: Branch B manager approves; warehouse manager notified read-only
13. Daily closing entry: mandatory; missed days flagged
14. Recipe override: full replace (no inheritance)
15. Disputed transfers immutable; resolution = corrective transfer
16. Out of scope: POS phone compulsion, order serial refactor, service-charge separation, SMS/Hubtel routing, finance module

---

## §7 Cross-Agent Contracts

### Events Consumed (from Order domain)

- `OrderCompleted` — triggers `IngredientDeductionForOrderJob`. Required payload: `order_id`, `branch_id`, `lines[]` with `menu_item_id`, `quantity`.
- `OrderCancelled` (post-completion) — triggers compensating reversal job.

### Events Emitted (to other domains)

- `StockMovementCreated` — broadcast to `inventory.location.{id}` channel for live dashboards.
- `LowStockDetected` — Notification + alert row.
- `WastageThresholdExceeded` — Notification to warehouse manager.
- `TransferDisputed` — Notification to warehouse manager.
- `DailyClosingMissed` — flagged on variance report (no notification by default).

### Data Read From

- `users`, `branches`, `menu_items` — read-only references.

### Data Written To Outside IMS

- **Nothing.** IMS never writes to non-IMS tables.

### Permission Registration

- IAM Auditor registers all `inventory.*` permissions via Spatie. See §3.

---

## §8 Open Questions

_None currently._ When new questions arise, add them here with date and context. Do not guess.

---

## §9 Changelog

### 2026-05-05

- KB initialized.
- All architectural decisions from session locked.
- Module not yet implemented; Phase 0 pending.
