---
description: "Use when: working on Inventory Management System (IMS), inventory features, stock movements, recipes, BOMs, transfers, requisitions, wastage, stock counts, warehouse, satellite kitchens, mother kitchen, purchasing clerk, warehouse manager, branch inventory, stock ledger, variance reports, costing. Always-on guardrails for the IMS module."
applyTo: "**"
---

# IMS Considerations — Always-On Guardrails

This file encodes the locked architectural decisions and operational rules for the **CediBites Inventory Management System (IMS)**. Every agent MUST honor these rules whenever IMS-adjacent work is being performed. These rules are non-negotiable until explicitly amended by the developer.

The full architecture lives in `cedibites_api/docs/inventory/architecture.md`. The domain specialist is the **Inventory Auditor** agent (`.github/agents/inventory-auditor.agent.md`) with persistent knowledge in `cedibites_api/docs/agents/inventory-auditor-kb.md`.

---

## 1. Locked Architectural Decisions

| #   | Decision                                                                                                                                                                                                                                                                                                                                                          |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Same domain, dedicated portal**: IMS lives at `cedibites/app/inventory/` — one Next.js app, one login, separate route group, separate sidebar, lazy-loaded. Pattern matches POS/KDS/Order Manager.                                                                                                                                                              |
| 2   | **Locations are separate**: `inventory_locations` table with `type` enum (`warehouse`/`satellite`). Optional `branch_id` FK back to `branches` for satellites. Branches table is NEVER modified.                                                                                                                                                                  |
| 3   | **Single warehouse for MVP**, schema supports N. Vocabulary: warehouse = "mother" / "central kitchen"; branches = "satellite kitchens".                                                                                                                                                                                                                           |
| 4   | **Append-only ledger + denormalized balances**: `inventory_stock_movements` is immutable (no soft-delete, no edits). `inventory_stock_balances` is the O(1) read cache. Reversals are NEW movements with opposite sign. Idempotency via `idempotency_key` UNIQUE index.                                                                                           |
| 5   | **Deduction trigger**: ingredients deducted on `OrderCompleted` event. Compensating reversal movements on post-completion cancellation. Job runs on dedicated `inventory` queue.                                                                                                                                                                                  |
| 6   | **Recipes (BOMs)**: global default + optional per-branch override. Override = **full replace** of ingredient list (no inheritance). Override creation gated to **Admin only** — Branch Managers cannot override. Recipe `status` enum: `draft` → `observation` → `locked`. Only `locked` recipes drive auto-deduction. Versioned via `inventory_recipe_versions`. |
| 7   | **Costing**: weighted-average cost. `unit_cost_at_time` recorded on every movement. FIFO/LIFO deferred.                                                                                                                                                                                                                                                           |
| 8   | **Batch / expiry / FEFO**: schema present from day one (`inventory_batches`); UI exposed in Phase 3.                                                                                                                                                                                                                                                              |
| 9   | **Tablet-first** UX for warehouse + branch operators. Mobile and desktop work but tablet is the design target.                                                                                                                                                                                                                                                    |
| 10  | **Stack**: Laravel 12 + MySQL + Sanctum + Spatie Permission + Reverb + Next.js 16 + TanStack Query. **No Supabase, no new dependencies without approval.**                                                                                                                                                                                                        |
| 11  | **Feature flag**: `IMS_ENABLED` env → `config('features.inventory.enabled')`. Middleware `EnsureInventoryEnabled` blocks all IMS routes when off. Frontend gates via `/api/me/features`. Merges to main are flag-OFF until UAT passes.                                                                                                                            |
| 12  | **Long-lived branch**: `feature/ims` in both repos. Frequent small flag-OFF PRs to main to avoid giant merges.                                                                                                                                                                                                                                                    |

---

## 2. Locked Workflow Rules

### Requisitions (multi-line invoice form)

- A requisition is a **multi-item document**, not one form per item.
- `purpose` enum: `opening` (start of day) | `supplementary` (mid-day shortage).
- `source_type` enum: `warehouse` | `branch`. If `branch`, branch picker shown.

### Transfers (status machine)

```
draft → submitted → approved → sent → received
                                    ↘ disputed → (corrective transfer created, original kept) → closed_disputed
```

- **Disputed transfers are immutable historical records.** Branch manager CANNOT silently edit "10 sent / 8 received" to 8. They mark disputed; warehouse manager creates a NEW corrective transfer linked via `parent_transfer_id`.
- **Source-stock validation**: when a transfer is being created, validate `source_balance >= requested_qty`. Block if deficit; allow only with explicit admin override permission, recorded in `source_validation_overridden_by`.
- **Inter-branch transfers**: Branch B's manager approves requests from Branch A. Warehouse manager receives a notification (read-only visibility), no veto.

### Daily Closing Entries (mandatory)

- At end of each business day, branch enters actual closing stock per item via `inventory_daily_closing_entries`.
- System computes `expected_qty` from ledger; branch inputs `actual_qty`; `variance = expected - actual`.
- **Mandatory** — missed days flagged on the variance report. Next day's opening = last known actual or expected fallback.
- This is **distinct** from `inventory_stock_counts` (full physical inventory, periodic).

### Reconciliation Cycles

- Manager-initiated, NOT calendar-enforced. Real-world cadence is monthly-to-quarterly.
- Warehouse manager opens an `inventory_reconciliation_cycles` row, system computes net variance, manager posts `cycle_adjustment` movements to reset to zero.

### Wastage

- Default threshold: **₵500** per event (configurable per location via `inventory_settings.wastage_threshold_amount`).
- Below threshold AND reason ≠ `spoiled_from_warehouse` → `auto_accepted`.
- Above threshold OR reason = `spoiled_from_warehouse` → `pending_approval` by warehouse manager.
- Conflict path: if warehouse manager disputes the wastage claim, status becomes `awaiting_physical_return`. Branch must physically return goods to warehouse (logged via `inventory_wastage_returns` + a return-direction transfer). Only after physical return does status move to `approved` or `rejected`.

### Stock Ledger Report (canonical columns — DO NOT invent variations)

```
Opening | Received | Transfers In | Transfers Out | Sales (BOM) | Wastage | Expected Closing | Actual Closing | Variance
```

### Reports (live under Reports section, NOT dashboard)

1. Stock Ledger | 2. Variance | 3. Daily Closing | 4. Wastage | 5. Dispute | 6. Transfer | 7. Purchases | 8. Cost-of-Sales | 9. Reorder Suggestion

### Dashboard (operational only)

Alerts · low-stock count · pending requisitions · pending wastage approvals · today's transfers · today's wastage value vs threshold. Nothing more.

---

## 3. Module Isolation Rules — Non-Negotiable

| Rule                                          | Enforcement                                                                                                                 |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| All IMS tables prefixed `inventory_`          | Every migration. Zero ALTERs to existing tables.                                                                            |
| Backend code lives in `app/Domain/Inventory/` | Sub-namespaced by sub-domain: `Movements/`, `Transfers/`, `Recipes/`, `Wastage/`, `Counts/`, `Reports/`, `Reconciliation/`. |
| FKs point OUT only                            | IMS may FK to `users`, `branches`, `menu_items`. NOTHING outside IMS may FK into `inventory_*`.                             |
| Order coupling is one-way                     | IMS subscribes to `OrderCompleted` / `OrderCancelled`. Order code knows nothing about IMS. If IMS fails, orders still ship. |
| Read-only on shared data                      | IMS reads `menu_items`, `branches`, `users`. Never writes.                                                                  |
| Dedicated queue                               | All IMS jobs dispatched to `inventory` queue. Never blocks payment/order queues.                                            |
| Routes in `routes/inventory.php`              | Registered conditionally based on feature flag.                                                                             |
| Frontend lives in `app/inventory/`            | Lazy-loaded route group. Hooks in `lib/api/hooks/inventory/`. Types in `types/inventory.ts`.                                |

---

## 4. Operator-Language UX Principle

**Simplicity is a product principle.** Every IMS UI must pass the **Branch Manager Test**: a non-technical operator (warehouse storekeeper, branch manager, purchasing clerk) understands what to do within 30 seconds without training.

- Use operator vocabulary in UI: "mother kitchen", "satellite kitchen", "requisition", "transfer", "received", "disputed", "closing stock", "wastage". NOT: "ledger entry", "movement record", "compensating txn".
- Accountant/developer jargon allowed in admin reports section only.
- Tablet-first: minimum 44px touch targets, single-column forms on tablet width, large stock-quantity inputs.
- Every destructive or financially-significant action requires confirmation modal.

---

## 5. Cross-Agent Notification Matrix (IMS-touching changes)

When work touches the items below, the listed agents MUST be looped in:

| IMS Change                             | Notify                                |
| -------------------------------------- | ------------------------------------- |
| `OrderCompleted` event contract change | Order Auditor + Inventory Auditor     |
| New `menu_items` field IMS depends on  | Menu Auditor + Inventory Auditor      |
| New role/permission                    | IAM Auditor + Inventory Auditor       |
| New KPI / report                       | Analytics Auditor + Inventory Auditor |
| New IMS portal page                    | UX Architect + Inventory Auditor      |
| Any meaningful change                  | Project Chronicle                     |

---

## 6. Out of Scope (Explicit)

The following were discussed but are **explicitly OUT of scope** for the IMS initiative:

- POS phone-number compulsion — POS is untouched.
- Order serial vs order number refactor — separate ticket, not IMS.
- Service charge separation in admin reports — separate ticket, not IMS.
- SMS broadcast/Hubtel routing changes — separate ticket, not IMS.
- Full finance/accounting module — post-MVP, not IMS scope.

Do not modify POS code, order numbering, messaging templates, or finance modules under the IMS initiative.

---

## 7. Open Questions Log

When new IMS questions arise that aren't answered here, record them in the Inventory Auditor KB §8 "Open Questions" — do not guess.
