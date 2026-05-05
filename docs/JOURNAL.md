# CediBites Development Journal

> **Workspace-wide.** Covers both `cedibites/` (frontend) and `cedibites_api/` (backend).
> Maintained by the **Scribe** agent. Append-only. One entry = one decision or finding.
> Entries ≤ 6 lines. Longer context lives in `docs/`; entries link there.

---

## Decisions

**2026-05-05** · IMS: Portal Location · IMS lives at `cedibites/app/inventory/` as a dedicated portal with its own layout and sidebar (entry tile from staff/admin dashboard).  
Why: consistent staff UX across all operator tools (POS/KDS/Order Manager pattern); lazy-loaded to avoid inflating other portal bundles.  
Source: IMS architecture session — [`docs/inventory/architecture.md`](inventory/architecture.md).

**2026-05-05** · IMS: Location Model · Dedicated `inventory_locations` table (`type`: `warehouse`|`satellite`, optional `branch_id` FK). `branches` table is never altered.  
Why: IMS isolation rule — zero ALTERs to existing tables; schema supports N locations from day one.  
Source: IMS architecture session.

**2026-05-05** · IMS: MVP Warehouse Count · Single warehouse for MVP; schema supports N warehouses.  
Why: reduces operational complexity for initial launch.  
Source: IMS architecture session.

**2026-05-05** · IMS: Ledger Pattern · `inventory_stock_movements` is append-only (no soft-delete, no edits). `inventory_stock_balances` is a denormalized read cache. Reversals = new rows with opposite sign. Idempotency via `idempotency_key` UNIQUE index.  
Why: complete audit trail; O(1) balance reads; safe under concurrent writes.  
Source: [`docs/inventory/architecture.md`](inventory/architecture.md).

**2026-05-05** · IMS: Deduction Trigger · Ingredients deducted on `OrderCompleted` event. Job dispatched to `inventory` queue. Compensating reversal on post-completion cancellation. Idempotent via `order_line_id`.  
Why: IMS must not block order flow; one-way event coupling means IMS failures cannot affect orders.  
Source: IMS architecture session.

**2026-05-05** · IMS: Recipes · Global default + per-branch override (Admin-only; full replace, no inheritance). Versioned via `inventory_recipe_versions`. Status: `draft → observation → locked`. Only `locked` recipes drive auto-deduction.  
Why: full replace avoids merge/inheritance complexity; status machine prevents accidental edits to live recipes.  
Source: IMS architecture session; detail in [`docs/inventory/architecture.md`](inventory/architecture.md).

**2026-05-05** · IMS: Costing Method · Weighted-average cost for MVP. `unit_cost_at_time` recorded on every movement.  
Why: simpler to implement than FIFO; provides acceptable accuracy for MVP.  
Source: IMS architecture session.

**2026-05-05** · IMS: Batch/FEFO · Schema (`inventory_batches`) present from day one. UI deferred to Phase 3.  
Why: schema now prevents costly future migration; UI scope reduced for Phases 0–2.  
Source: IMS architecture session.

**2026-05-05** · IMS: UX Target · Tablet-first (≥ 768px). Operator vocabulary in all non-report screens: "mother kitchen", "satellite kitchen", "requisition", "transfer", "received", "disputed", "closing stock", "wastage". Accountant/developer jargon only in admin reports.  
Why: "Branch Manager Test" — non-technical operator must understand any screen within 30 seconds, no training.  
Source: IMS architecture session.

**2026-05-05** · IMS: Stack Lock · Laravel 12 + MySQL + Sanctum + Spatie Permission + Reverb + Next.js 16 + TanStack Query. No Supabase. No new dependencies without explicit developer approval.  
Why: stability; no added vendor complexity during IMS build.  
Source: IMS architecture session.

**2026-05-05** · IMS: Feature Flag · `IMS_ENABLED` env → `config('features.inventory.enabled')`. Middleware `EnsureInventoryEnabled` blocks all IMS routes. Frontend gates via `/api/me/features`. All merges flag-OFF until UAT passes.  
Why: allows incremental merge to main/master without exposing incomplete features to operators.  
Source: IMS architecture session.

**2026-05-05** · IMS: Long-Lived Branch · `feature/ims` in both repos. Small flag-OFF PRs to `main`/`master`. Branch NOT yet cut — blocked on DevOps audit (see Open Questions).  
Why: avoids a single massive merge; flag-OFF ensures nothing reaches prod prematurely.  
Source: IMS architecture session.

**2026-05-05** · IMS: Inter-Branch Transfers · Branch B's manager approves transfer requests from Branch A. Warehouse manager is notified (read-only, no veto).  
Why: not stated explicitly in conversation.  
Source: IMS architecture session (G1 answer).

**2026-05-05** · IMS: Daily Closing Entry · Mandatory per branch per day. Missed days flagged on variance report. Next day's opening = last known actual, or expected as fallback.  
Why: variance tracking requires a daily actual baseline; optional entries produce untrustworthy data.  
Source: IMS architecture session (G2 answer).

**2026-05-05** · IMS: Disputed Transfers · Disputed records are immutable. Branch manager marks disputed; warehouse manager creates a new corrective transfer linked via `parent_transfer_id`. Original never edited.  
Why: "10 sent / 8 received" must remain as historical fact; correction produces a linked new audit record.  
Source: IMS architecture session.

**2026-05-05** · IMS: Source-Stock Validation · Stock availability validated when a transfer is created. Deficit blocks creation. Override requires explicit admin permission; recorded in `source_validation_overridden_by`.  
Why: prevents overdraw; admin override preserves operational flexibility with full audit trace.  
Source: IMS architecture session.

**2026-05-05** · IMS: Wastage Threshold · Default ₵500 per event, configurable per location via `inventory_settings.wastage_threshold_amount`. Below threshold AND reason ≠ `spoiled_from_warehouse` → `auto_accepted`. Otherwise → `pending_approval` by warehouse manager.  
Why: threshold prevents uncontrolled bypass of approval flow for small losses; ₵500 figure from transcript.  
Source: IMS architecture session; conflict path detail in [`docs/inventory/architecture.md`](inventory/architecture.md).

**2026-05-05** · IMS: Reconciliation Cycles · Manager-initiated, not calendar-enforced. Warehouse manager opens a cycle row; system computes net variance; manager posts `cycle_adjustment` movements.  
Why: real-world cadence is monthly-to-quarterly and varies by operation; calendar enforcement creates forced cycles with no staff available to action them.  
Source: IMS architecture session.

**2026-05-05** · IMS: Stock Ledger Columns (Locked) · Canonical columns — Opening · Received · Transfers In · Transfers Out · Sales (BOM) · Wastage · Expected Closing · Actual Closing · Variance. No variations permitted.  
Why: agreed column set from design session; must be consistent across all agents and UI implementations.  
Source: IMS architecture session.

**2026-05-05** · IMS: Purchase Orders In Scope · PO module added to MVP. Tables: `inventory_purchase_orders` + `inventory_purchase_order_items` + `inventory_purchases` (receipts) + `inventory_purchase_items`. Status machine: `draft → sent → partially_received → received → closed` (+ `cancelled` from any pre-receipt state with reason).  
Why: closes the loop between "what was ordered" and "what arrived"; provides supplier performance + variance visibility; required for Reorder Suggestion engine.  
Source: Developer decision, 2026-05-05.

**2026-05-05** · IMS: PO Discipline · Strict mode — every purchase MUST tie to a PO. Single override flag `urgent_buy=true` (with required reason) lets Purchasing Clerk record an ad-hoc purchase without a PO. Urgent buys flagged in reports.  
Why: discipline first, escape hatch for emergencies; reportable so abuse is visible.  
Source: Developer decision, 2026-05-05.

**2026-05-05** · IMS: PO Authorship · Only `WarehouseManager` may create POs. `PurchasingClerk` only executes (records purchases against existing POs, or urgent-buy override).  
Why: separation of duties — clerk who buys is not the clerk who authorises.  
Source: Developer decision, 2026-05-05.

**2026-05-05** · IMS: PO Approval Threshold · POs above a configurable amount (default TBD, suggest ₵10,000) require Admin approval before status can move from `draft → sent`. Below threshold auto-approves on submit.  
Why: financial control on large commitments without slowing routine purchases.  
Source: Developer decision, 2026-05-05.

**2026-05-05** · IMS: Build Order · Frontend Warehouse Manager portal first (mock-backed). All backend work (PO migrations, controllers, engines) deferred until WM portal UX is locked.  
Why: design-led — get the operator experience right before committing to schema/API contracts.  
Source: Developer decision, 2026-05-05.

**2026-05-05** · IMS: Test Credentials Seeded · `EmployeeSeeder` now creates `warehouse@cedibites.test` (WarehouseManager) and `purchasing@cedibites.test` (PurchasingClerk), both password `password`. Branches: all.  
Why: unblocks IMS portal access testing without ad-hoc DB edits.  
Source: Developer decision, 2026-05-05.

**2026-05-05** · IMS: feature/ims Branch Cut · Both repos now on `feature/ims`. Frontend has IMS UI work uncommitted; backend has migrations + IMS skeleton + new roles uncommitted.  
Why: long-lived branch per locked decision; supersedes prior "not yet cut" entry.  
Source: Workspace state inspection, 2026-05-05.

**2026-05-05** · Scribe Agent · Created workspace-wide `docs/JOURNAL.md` (this file, in `cedibites_api/`). Single file covers both repos. Agent files at `.github/agents/scribe.agent.md` in both repos.  
Why: Project Chronicle handles session narratives; Scribe handles atomic decision ledger; separate roles — different cadences, different edit scopes.  
Source: Developer request, 2026-05-05.

**2026-05-05** · Scribe: Fixed Sections · Section order (immutable): Decisions · Rejected · Deferred · Open Questions · Cross-Repo Impact.  
Why: developer-approved starter set; fixed order prevents section drift across future agents.  
Source: Developer request, 2026-05-05.

**2026-05-05** · Scribe: No Confirmation Before Logging · The Scribe logs all journal-worthy turns immediately without asking the developer for permission first.  
Why: "the scribe should always log without asking" — developer instruction.  
Source: Developer request, 2026-05-05.

**2026-05-05** · DevOps: Beta Cascade Removed · `workflow_run` trigger (prod success → beta overwrite) removed from `cedibites_api/.github/workflows/deploy-beta.yml`. Beta now deploys only on explicit push to `beta` or manual `workflow_dispatch`.  
Why: every prod deploy was destroying whatever was running on beta; this blocked IMS UAT on beta independently of production.  
Source: DevOps audit, 2026-05-05.

**2026-05-05** · DevOps: Manual Dispatch on Beta Workflows · Both `deploy-beta.yml` files (backend + frontend) now accept `workflow_dispatch` with a `branch` input. Any branch (e.g. `feature/ims`) can be deployed to beta without merging to `beta`.  
Why: IMS UAT requires deploying `feature/ims` to beta for testing before any merge to `master`/`main`.  
Source: DevOps audit, 2026-05-05.

**2026-05-05** · DevOps: Conditional Seeders · `PermissionSeeder` and `RoleSeeder` now only run when `php artisan migrate --force` output indicates new migrations were applied. Routine deploys with no schema changes skip seeding.  
Why: seeders ran on every deploy regardless of schema changes; unnecessary and slow.  
Rule: new permissions must always ship with at least one migration file or they will not seed on deploy.  
Source: DevOps audit, 2026-05-05.

**2026-05-05** · DevOps: Hard Reset on All 4 Workflows · Frontend workflows switched from `git pull` to `git fetch + reset --hard`. All 4 workflows now use hard reset. `set -e` added to all deploy scripts.  
Why: `git pull` fails if server has local modifications; hard reset is deterministic. `set -e` ensures any command failure aborts the deploy immediately.  
Source: DevOps audit, 2026-05-05.

**2026-05-05** · DevOps: Post-Deploy Health Checks · Backend: `php artisan about --only=application` (confirms Laravel can bootstrap + DB connects). Frontend: `pm2 show <process> | grep online` with 3-second settle time.  
Why: previously, a failed migration or crashed PM2 process left production silently broken until a human noticed.  
Source: DevOps audit, 2026-05-05.

---

## Rejected

**2026-05-05** · IMS Costing: FIFO/LIFO for MVP · Rejected in favour of weighted-average cost.  
Why: simpler to implement; deferred to post-MVP.  
Source: IMS architecture session.

**2026-05-05** · IMS: Calendar-Enforced Reconciliation · Rejected in favour of manager-initiated cycles.  
Why: real-world cadence varies; calendar enforcement creates forced cycles when no staff are available.  
Source: IMS architecture session.

**2026-05-05** · IMS: Recipe Override via Delta/Inheritance · Rejected in favour of full-replace override.  
Why: merge/inheritance logic is ambiguous and complex; full replace is unambiguous.  
Source: IMS architecture session (G3 answer).

**2026-05-05** · IMS: Editing Disputed Transfer Records · Rejected. Disputed records are immutable; resolution uses a new corrective transfer.  
Why: "10 sent / 8 received" must remain as historical fact; editing it destroys the audit trail.  
Source: IMS architecture session.

**2026-05-05** · IMS: Supabase / New Backend Dependencies · Rejected. Stack locked.  
Why: stability; no new vendor complexity during IMS build.  
Source: IMS architecture session.

**2026-05-05** · DevOps: workflow_run Cascade (prod→beta auto-sync) · Rejected. Removed entirely.  
Why: destroyed beta state on every prod deploy; incompatible with independent beta staging for IMS UAT.  
Source: DevOps audit, 2026-05-05.

**2026-05-05** · Scribe: Merge Into Project Chronicle · Rejected. Two agents retained.  
Why: different cadences (per-session narrative vs per-decision atomic) and edit scopes would dilute both if merged.  
Source: Developer request, 2026-05-05.

---

## Deferred

**2026-05-05** · IMS: Batch/FEFO UI · Schema present day one (`inventory_batches`). UI deferred to Phase 3.  
Source: IMS architecture session.

**2026-05-05** · IMS: FIFO Costing · Deferred to post-MVP. Weighted-average used for MVP.  
Source: IMS architecture session.

**2026-05-05** · IMS: Full Finance/Accounting Module · Explicitly out of scope for IMS. Separate post-MVP initiative.  
Source: IMS architecture session (G4 out-of-scope list).

**2026-05-05** · IMS: Phase 0 Branch Cut · `feature/ims` branch not yet created in either repo. Pipeline now cleared — ready to cut when developer gives the signal.  
Source: IMS architecture session; DevOps audit resolved 2026-05-05.

**2026-05-05** · Out of Scope (POS/Order/SMS) · POS phone-number compulsion, order serial/number refactor, service-charge separation in admin reports, SMS/Hubtel routing changes — all explicitly deferred/out of scope. Do not touch under IMS initiative.  
Source: IMS architecture session (G4 answer).

---

## Open Questions

~~**2026-05-05** · DevOps: beta↔main Auto-Merge · Does the pipeline auto-merge beta to main/master?~~ *(resolved 2026-05-05 — see Decisions)*

**2026-05-05** · `OrderCompleted` Event Payload · Does the event include `branch_id` and line-level `menu_item_id` + `quantity`? Required for Phase 3 ingredient deduction.  
Action needed: Order Auditor to inspect `app/Events/` before Phase 3 begins.

---

## Cross-Repo Impact

**2026-05-05** · IMS: New Roles · `Purchasing Clerk` and `Warehouse Manager` seeded in backend (`RoleSeeder` + `EmployeeSeeder`). Frontend `cedibites/app/inventory/` must gate IMS pages by these roles via `/api/me/features` + permission checks.  
Status: backend seeded with test users; frontend gating not yet implemented.

**2026-05-05** · IMS: PO Permissions · New permissions needed when backend work begins: `inventory.purchase_order.create`, `inventory.purchase_order.approve`, `inventory.purchase_order.cancel`, `inventory.purchase.urgent_buy`. To be added to `WarehouseManager` (all 4) and `PurchasingClerk` (urgent_buy only). Existing `inventory.purchase.create` / `inventory.purchase.view` already on both roles.  
Status: deferred — backend work paused until WM portal UX is locked.

**2026-05-05** · IMS: `OrderCompleted` Event Coupling · Backend must emit `OrderCompleted` with `branch_id` + order lines for ingredient deduction. Frontend Order Manager portal must remain compatible. No frontend changes needed until Phase 3 confirmed.  
Status: architecture decision locked; event payload unverified (see Open Questions).
