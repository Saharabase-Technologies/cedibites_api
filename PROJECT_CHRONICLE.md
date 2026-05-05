# CediBites API — Project Chronicle

> **Purpose**: Living record of all changes, decisions, and current state of the CediBites Laravel API. Maintained by the Project Chronicle agent. Read this before starting work on any area.

> **Current Branch**: `master` / `beta` (synced)

---

## System Map

### Route Files

| Route File             | Domain                                      |
| ---------------------- | ------------------------------------------- |
| `routes/auth.php`      | Registration, login, password reset         |
| `routes/public.php`    | Public menu, branches, categories (no auth) |
| `routes/cart.php`      | Shopping cart operations                    |
| `routes/protected.php` | General authenticated endpoints             |
| `routes/employee.php`  | Employee profile, POS orders, shifts        |
| `routes/manager.php`   | Branch manager: employees, orders, stats    |
| `routes/admin.php`     | Admin CRUD, system management               |
| `routes/promos.php`    | Promo/discount management                   |
| `routes/channels.php`  | WebSocket/Reverb broadcasting channels      |

### Models (31)

**Core**: User, Customer, Employee, EmployeeNote, Branch, Address
**Menu**: MenuItem, MenuCategory, MenuTag, MenuAddOn, MenuItemOption, MenuItemOptionBranchPrice, MenuItemRating, SmartCategorySetting
**Orders**: Order, OrderItem, OrderStatusHistory, ShiftOrder, CheckoutSession
**Shopping**: Cart, CartItem
**Financial**: Payment, Promo
**Operations**: Shift, BranchOperatingHour, BranchDeliverySetting, BranchOrderType, BranchPaymentMethod
**System**: Otp, SystemSetting, ActivityLog

### Services (9)

| Service                  | Purpose                                                                |
| ------------------------ | ---------------------------------------------------------------------- |
| `OrderCreationService`   | Order placement from checkout sessions (DB transaction, lockForUpdate) |
| `OrderManagementService` | Status changes, cancellations                                          |
| `OrderNumberService`     | Order code generation                                                  |
| `HubtelPaymentService`   | Hubtel mobile money gateway                                            |
| `HubtelSmsService`       | SMS via Hubtel                                                         |
| `OTPService`             | OTP generation/validation                                              |
| `PromoResolutionService` | Promo code validation & discount                                       |
| `AnalyticsService`       | Dashboard metrics & reporting                                          |
| `SystemSettingService`   | Global config (cache-backed, 1hr TTL)                                  |
| `SmartCategoryService`   | Smart category orchestration (strategy pattern, 6hr cache TTL)         |

### Key Architecture

- **Payment-first checkout**: CheckoutSession → Payment → OrderCreationService → Order (never direct order creation)
- **Real-time**: Reverb broadcasting on `orders.branch.{id}` and `orders.{number}` channels
- **Order state machine**: received → accepted → preparing → ready → out_for_delivery/ready_for_pickup → delivered/completed
- **Cancel flow**: Staff request → Admin approve/reject
- **Observer pattern**: OrderObserver (lifecycle hooks), PaymentObserver (state transitions)

### Notifications (16)

Order lifecycle (7): NewOrder, Confirmed, Preparing, Ready, OutForDelivery, Completed, Cancelled
Staff (2): AccountCreated, PasswordReset
User auth (2): Welcome, PasswordResetRequired
System (3): OTP, PaymentFailed, HighValueOrder
Business (2): BranchManagerAssigned, BranchManagerRemoved

### Integrations

- **Hubtel Payment Gateway** — Mobile money (Standard for web, RMP for POS USSD)
- **Hubtel SMS** — Notification delivery
- **Reverb** — WebSocket broadcasting
- **Spatie Media Library** — Menu item images
- **Spatie Permission** — Role-based access control
- **Spatie Activity Log** — Audit trail

---

## Change Log

_Entries are added per session, newest first._

<!--
Template for new entries:

## [YYYY-MM-DD] Session: Brief Title

### Intent
What the engineer wanted to achieve.

### Changes Made
| File | Change | Reason |
|------|--------|--------|
| path/to/file.php | Description | Why |

### Decisions
- **Decision**: description
  - **Rationale**: why

### Current State
What the system looks like after changes.

### Pending / Follow-up
Items still needing attention.
-->

---

## [2026-05-05] Session: IMS Test Users Seeded + PO Decisions Journaled

### Intent

Provide working test credentials for the new Warehouse Manager portal build, and journal all PO-related architectural decisions made during the planning phase. No production code touched — backend PO work explicitly deferred until the frontend flows are validated against mock data.

### Changes Made

| File | Change | Reason |
|------|--------|--------|
| `database/seeders/EmployeeSeeder.php` | Added `createWarehouseManager()` and `createPurchasingClerk()` methods; both users synced to all branches | Provides login credentials for the two new IMS personas being designed against |
| `docs/JOURNAL.md` | Added 7 new decision entries under 2026-05-05 covering PO scope, strict+urgent_buy mode, WM-only authorship, ₵10K approval threshold, frontend-first build order, seeded creds, branch cut | Atomic decision ledger for cross-agent and cross-session continuity |

### Decisions

- **Decision**: Add Purchase Order workflow to IMS scope (was originally deferred). **Rationale**: Without a formal PO, the PurchasingClerk has no authoritative document to receive against; closes the loop between WM and clerk roles.
- **Decision**: Strict mode by default — purchases must reference an open PO. `urgent_buy` boolean overrides for emergency buys. **Rationale**: Discipline for the normal flow with a documented escape hatch for reality.
- **Decision**: Only `WarehouseManager` role can author POs. `PurchasingClerk` only records purchases against existing POs. **Rationale**: Separation of duties — clerk executes, manager authorises.
- **Decision**: PO approval threshold = ₵10,000 (`PO_APPROVAL_THRESHOLD` constant). Above → requires Admin approval before `submit → sent`. **Rationale**: Mirrors wastage threshold pattern; financial gate without micromanaging small POs.
- **Decision**: Build frontend WM portal first against mocks; defer ALL PO backend work (migration, model, service, controller, permissions, routes). **Rationale**: Lets UX iterate with real users on mock data; backend ships only after flows are validated, avoiding throwaway work.
- **Decision**: Test creds — `warehouse@cedibites.test` / `password` (employee_no `WHM0001`) and `purchasing@cedibites.test` / `password` (employee_no `PCK0001`). **Rationale**: Stable, memorable identifiers for the two personas the frontend is being built for.
- **Decision**: All work continues on long-lived branch `feature/ims` in both repos. **Rationale**: Already established in earlier session; no reason to deviate.

### Current State

- Branch: `feature/ims` (commit `d2562b7`)
- Two new test users live in local DB:
  - `warehouse@cedibites.test` — `warehouse_manager` role, all branches synced, employee_no `WHM0001`
  - `purchasing@cedibites.test` — `purchasing_clerk` role, all branches synced, employee_no `PCK0001`
- Both roles already exist via `RoleSeeder`/`PermissionSeeder` (no permission changes needed)
- Zero changes to controllers, services, models, migrations, routes, or PO-domain code

### Pending / Follow-up

- **Backend PO domain (deferred until frontend validated)**:
    - `inventory_purchase_orders` migration (status enum: draft / pending_approval / sent / partially_received / received / closed / cancelled)
    - `inventory_purchase_order_items` line table
    - `inventory_purchases` + `inventory_purchase_items` for receipts (with `urgent_buy` flag + nullable `purchase_order_id` FK)
    - `app/Domain/Inventory/Purchases/` engines: `PurchaseOrderEngine`, `PurchaseReceiptEngine` (writes movements + updates PO `received_qty`)
    - Permissions: `purchase-orders.create`, `purchase-orders.approve`, `purchase-orders.cancel`, `purchase-orders.close`, `purchases.record`, `purchases.urgent-buy`
    - Routes under `routes/inventory.php` gated by `EnsureInventoryEnabled`
    - Pest tests for both engines including the ₵10K threshold logic
- Pre-existing seeder ordering gotcha: `EmployeeSeeder` requires `PermissionSeeder` + `RoleSeeder` to run first or roles fail to attach (worked around manually)

---

## [2026-05-05] Session: IMS Architecture Lock + Scribe Agent

### Intent

1. Lock all architectural decisions for the Inventory Management System (IMS) before any code is written.
2. Create a workspace-wide atomic decision ledger (the **Scribe** agent) that persists every locked decision, rejection, deferral, and open question so agents read it at activation.

### Changes Made

| File                                                      | Change                                                                                                               | Reason                                                                 |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `.github/instructions/ims-considerations.instructions.md` | Created — always-on IMS guardrails, all 19 locked decisions                                                          | Prevents any agent from violating IMS constraints accidentally         |
| `.github/instructions/code-quality.instructions.md`       | Created — backend code-quality and modularity rules (file caps, engine pattern, anti-patterns, pre-commit checklist) | Enforces SRP, no god-services, explicit return types across entire API |
| `.github/agents/inventory-auditor.agent.md`               | Created — Inventory Auditor specialist agent for IMS domain                                                          | Dedicated agent for all IMS backend work                               |
| `.github/agents/scribe.agent.md`                          | Created — Scribe agent full spec (hard rules, entry format, git/PR workflow, deduplication)                          | Atomic decision ledger agent                                           |
| `docs/agents/inventory-auditor-kb.md`                     | Created — Inventory Auditor persistent knowledge base (§1–§9)                                                        | Carries IMS domain knowledge across sessions                           |
| `docs/inventory/architecture.md`                          | Created — formal IMS architecture document (ERD, workflow specs, phase roadmap)                                      | Source of truth for all IMS implementation work                        |
| `docs/JOURNAL.md`                                         | Created — workspace-wide decision journal, backfilled with all IMS decisions                                         | Living ledger read by all agents at activation                         |

### Decisions

- **Decision**: Scribe agent is separate from Project Chronicle.
    - **Rationale**: Chronicle handles session narratives; Scribe handles atomic ≤6-line decision entries. Different cadences and edit scopes — merging them would dilute both.
- **Decision**: Single `docs/JOURNAL.md` in `cedibites_api/` covers both repos.
    - **Rationale**: Multi-root workspace; one file avoids synchronisation issues between two journal files.
- **Decision**: Scribe uses `Claude Sonnet 4.5`.
    - **Rationale**: Task requires precision and strict rule-following, not deep reasoning. Sonnet is faster and cheaper for this workload.
- **IMS decisions**: 19 architectural decisions locked. Full list in `docs/JOURNAL.md` §Decisions and `docs/inventory/architecture.md`.

### Current State

- IMS: architecture locked and documented. Zero IMS code written.
- Scribe: agent live, journal backfilled with all prior session decisions.
- `feature/ims` branches **not yet cut** — blocked on DevOps audit (beta↔main auto-merge question unresolved).

### Pending / Follow-up

- DevOps audit: confirm whether beta auto-merges to master; gates all IMS PRs to master.
- `PROJECT_CHRONICLE.md` updates in `cedibites/` (frontend) — same session, done simultaneously.
- Phase 0 kickoff: scaffold, feature flag, route group, permissions, locations table, items/categories/units/suppliers CRUD, warehouse-portal shell.

---

## [2026-05-01] Session: Order Period Summary Endpoint + Audit Log Causers Endpoint

### Intent

Two small additive endpoints to power new admin/staff observability features:

1. A lightweight per-scope order quality summary (valid / cancelled / failed / refunded / no-charge counts and amounts) that mirrors the orders index filter pipeline so the numbers always match the visible table.
2. A distinct-causers list for the admin audit log "filter by user" dropdown, narrowed by date range.

### Changes Made

| File                                                   | Change                                                                               | Reason                                                                                                                                                             |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Services/OrderManagementService.php`              | Added `getBranchPeriodSummary(User, array)` method                                   | Reuses the existing `getBranchOrders()` filter pipeline (role/branch scoping + all filters) then runs five aggregate clones to produce per-status counts + amounts |
| `app/Http/Controllers/Api/EmployeeOrderController.php` | Added `summary(Request)` action                                                      | Thin orchestration — pulls allowed filter keys from request, delegates to service                                                                                  |
| `routes/employee.php`                                  | Added `GET v1/employee/orders/summary` (gated by `permission:view_orders`)           | Same auth gate as `index`                                                                                                                                          |
| `app/Http/Controllers/Api/ActivityLogController.php`   | Added `causers(Request)` action                                                      | Returns distinct `causer_id` values from `activity_log` (User class only) within optional date range, joined with users for `{id, name, email}`                    |
| `routes/admin.php`                                     | Added `GET v1/admin/activity-logs/causers` (gated by `permission:view_activity_log`) | Same gate as the audit log itself                                                                                                                                  |

### Decisions

- **Decision**: Use five separate `whereHas` aggregate clones instead of one `LEFT JOIN payments` with `CASE` sums.
    - **Rationale**: An order can have multiple `payments` rows (retries, partial refunds). A flat join would double-count `total_amount`. Per-status `whereHas` clones keep counts/sums one-per-order accurate.
- **Decision**: Mirror `AnalyticsQueryBuilder` definitions exactly — "valid" = `status != cancelled` AND `whereHas('payments', payment_status = completed)`.
    - **Rationale**: Numbers shown alongside an orders table must match the dashboards or trust collapses. Same source of truth.
- **Decision**: Strip the eager loads + ordering off the cloned base before aggregating.
    - **Rationale**: Eager loads + `ORDER BY` are expensive and irrelevant for `COUNT`/`SUM`.
- **Decision**: Do NOT extend the existing `stats` endpoint — added a new `summary` endpoint instead.
    - **Rationale**: `stats` is "today snapshot" semantics used by the staff home dashboard. Conflating it with filter-scoped summary would have changed behaviour for existing callers. Additive, no breakage.
- **Decision**: Audit log causers narrowed by date range (optional).
    - **Rationale**: Keeps the dropdown short and contextually relevant — only users who actually acted in the visible window appear.

### Current State

- Two new GET endpoints, both gated by existing permissions, both additive (no existing endpoints modified).
- `vendor/bin/pint --dirty` passes.
- `php artisan route:list --path=employee/orders` confirms `summary` route is registered.

### Pending / Follow-up

- Optional: cache the summary briefly per-scope hash to reduce load if dashboards become refresh-heavy.

---

## [2026-04-28] Session: SMS Phone Normalization + SmartError Mislabeling Fix

### Intent

Fix two related production issues surfaced by an admin error notification labeled "Hubtel payment gateway error" against an SMS failure for customer #519 (phone `536742248`):

1. SMS notifications were failing because phone numbers stored without a country code (`536742248`) couldn't pass `HubtelSmsService::validatePhoneNumber()` (which requires `233XXXXXXXXX`).
2. The `SmartErrorService` was mislabeling **all** errors containing the word "Hubtel" as payment gateway errors — including SMS failures from `HubtelSmsService`.

### Changes

- **`app/Channels/SmsChannel.php`** — Added phone normalization before calling `HubtelSmsService::sendSingle()`:
    - Strips `+` prefix (existing behavior).
    - `0241234567` (10-digit local) → `233241234567`.
    - `241234567` (bare 9-digit) → `233241234567`.
    - Already-correct `233...` numbers pass through unchanged.
- **`app/Services/SmartErrorService.php`** — Added a more specific matcher for `HubtelSmsService` / `Invalid phone number format` errors that now categorize as `integrations` with title "SMS notification failed", placed **before** the generic Hubtel matcher.

### Decisions

- Phone normalization lives in `SmsChannel` (not the model) so other consumers of `User.phone` aren't affected.
- The change is strictly additive — any phone format that succeeded before still succeeds; nothing that worked before can break.
- Order placement is unaffected: all `Order*Notification` classes implement `ShouldQueue`, so SMS sending happens in a queue worker fully decoupled from the HTTP request.

### Files Modified

- `app/Channels/SmsChannel.php`
- `app/Services/SmartErrorService.php`

### Operational Note (not a code change)

Created a production `tech_admin` user via tinker on the server (`/var/www/production/laravel/cedibites_api`). The `EmployeeSeeder` only creates the `admin` role user, so additional tech admins must be provisioned manually. Procedure used:

1. `User::create([...])` with `name`, `email`, `phone`, `password`.
2. `$user->syncRoles([Role::TechAdmin->value])`.
3. `Employee::create([...])` with `employee_no`, `status: Active`, `hire_date`.
4. `$emp->branches()->sync(Branch::pluck('id'))`.
5. Set `platform_passcode` (6-digit PIN, hashed) for Platform Admin vault access.

### Current State

- Customer phones in any common Ghana format (`+233...`, `233...`, `0...`, bare 9-digit) now produce successful SMS.
- Admin error feed correctly distinguishes SMS failures from payment failures.

---

## [2026-04-14] Session: Cancel Request Double-Fire Bug Fix

### Intent

Fix a bug where staff clicking "Request Cancellation" once on the manager orders page resulted in TWO API requests — the first returning 500 (Internal Server Error), the second returning 422 ("Cannot request cancellation for an order with status 'cancel_requested'"). The order did transition correctly despite the error shown to the user.

### Root Cause

Two compounding bugs:

1. **Frontend mutation retry**: TanStack Query's `QueryProvider.tsx` had `retry: 1` for mutations. When the first cancel request returned 500, TanStack Query automatically retried — causing the phantom "double click".
2. **Backend non-atomic state change**: `requestCancel()` was NOT wrapped in `DB::transaction()`. The `$order->update()` committed to DB, but something after it (activity log or eager loading) threw an exception → 500. The retry then found the order already in `cancel_requested` → 422.

### Changes Made

| File                                                   | Change                                                       | Reason                                                                                                                                                  |
| ------------------------------------------------------ | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/CancelRequestController.php` | Wrapped `requestCancel()` method body in `DB::transaction()` | If activity log or eager loading fails after status update, the status change rolls back — prevents partial commits leaving order in inconsistent state |
| `app/Http/Controllers/Api/CancelRequestController.php` | Wrapped `rejectCancel()` method body in `DB::transaction()`  | Same atomicity fix — status change + activity log + eager loading must all succeed or all roll back                                                     |

### Decisions

- **Decision**: Wrap `requestCancel()` and `rejectCancel()` in `DB::transaction()` — `approveCancel()` was already transactional
    - **Rationale**: All state-changing methods that update order status AND perform follow-up operations (activity log, eager loading) must be atomic. A partial commit (status saved, but activity log error → 500) is the root cause of the double-fire bug on the frontend.
- **Decision**: Did NOT change `approveCancel()`
    - **Rationale**: Already wrapped in a transaction — no fix needed.

### Current State

- **`requestCancel()`**: Fully transactional — status update + activity log + eager load happen atomically
- **`rejectCancel()`**: Fully transactional — same atomicity guarantee
- **`approveCancel()`**: Already transactional (unchanged)
- **Cancel flow overall**: 500 errors from post-update failures will now roll back the status change, so the order won't be left in `cancel_requested` if the response fails

### Cross-Repo Impact

| File (Frontend repo)                         | Change                                              | Impact                                                                                                               |
| -------------------------------------------- | --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `app/components/providers/QueryProvider.tsx` | Mutations changed from `retry: 1` to `retry: false` | Prevents TanStack Query from auto-retrying failed mutations — the frontend amplification that caused the double-fire |

### Pending / Follow-up

- Investigate the original 500 error root cause in production logs (`storage/logs/laravel.log`) — likely activity logging or eager loading failure inside `requestCancel()`
- Audit other controller methods for similar non-transactional state changes (status update outside `DB::transaction()`) that could cause partial commits

---

## [2026-04-12] Session: Admin Staff Sales Endpoint

### Intent

Add an admin-level staff sales analytics endpoint so the new Admin Staff Sales page can query per-staff revenue breakdowns across all branches with date range and branch filters.

### Changes Made

| File                                                    | Change                                                                                                                                | Reason                                                                                                         |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/AdminAnalyticsController.php` | Added `staffSales()` method delegating to `AnalyticsService::getStaffSalesMetrics()` with `date_from`, `date_to`, `branch_id` filters | Admin needs cross-branch staff sales view — reuses same service as manager endpoint for single source of truth |
| `routes/admin.php`                                      | Registered `GET admin/analytics/staff-sales` under existing `permission:view_orders` middleware group                                 | Consistent with all other admin analytics route permissions                                                    |

### Decisions

- **Decision**: Reuse `AnalyticsService::getStaffSalesMetrics()` instead of creating a separate method
    - **Rationale**: The method already supports optional `branch_id` and date range filters. Without `branch_id`, it returns all branches — exactly what admin needs.
- **Decision**: Place under `view_orders` permission, not `view_employees`
    - **Rationale**: Staff sales data is order/revenue data, not employee profile data. Consistent with all other analytics endpoints.

### Current State

- `GET /admin/analytics/staff-sales` available with optional `branch_id`, `date_from`, `date_to` query params
- Returns same `AdminStaffSalesRow[]` shape as the manager's branch staff-sales endpoint
- Gated by `view_orders` permission alongside all other admin analytics routes

### Cross-Repo Impact

| File (Frontend)                         | Change                                      |
| --------------------------------------- | ------------------------------------------- |
| `app/admin/staff/sales/page.tsx`        | New page consuming this endpoint            |
| `lib/api/services/analytics.service.ts` | Added `getAdminStaffSales()` service method |
| `lib/api/hooks/useAnalytics.ts`         | Added `useAdminStaffSales()` hook           |

---

## [2026-04-12] Session: BM Staff Sales Fix + manual_momo Support

### Intent

Fix Branch Manager Staff Sales page showing "No sales recorded" despite having data. Root cause was a PostgreSQL `SQLSTATE[42702]: Ambiguous column` error in `getStaffSalesMetrics()`. Also add `manual_momo` as a distinct payment method metric and merge `cash_on_delivery` into the `cash` bucket.

### Changes Made

| File                                          | Change                                                                                                                                                                | Reason                                                                                                                                                                                                                                                                     |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Services/Analytics/AnalyticsService.php` | Rewrote `getStaffSalesMetrics()` — replaced `placedOrders($filters)` with `Order::query()` + explicit JOINs + `applyFilters($query, $filters, 'orders')` table prefix | Fix ambiguous column PostgreSQL error: `placedOrders()` applied `whereDate('created_at', ...)` without table prefix, and after JOINing `payments`, `employees`, and `users` (all with `created_at` columns), PostgreSQL couldn't resolve which table's `created_at` to use |
| `app/Services/Analytics/AnalyticsService.php` | Merged `cash_on_delivery` into `cash` bucket: `WHERE payment_method IN ('cash', 'cash_on_delivery')`                                                                  | Business decision: cash_on_delivery is just cash — should not be a separate category                                                                                                                                                                                       |
| `app/Services/Analytics/AnalyticsService.php` | Added `manual_momo_total` and `manual_momo_count` as standalone columns                                                                                               | Direct MoMo ("sent to branch number") is a distinct payment method needing its own tracking                                                                                                                                                                                |
| `app/Services/Analytics/AnalyticsService.php` | Changed all `SUM(orders.total_amount)` → `SUM(payments.amount)`                                                                                                       | Prevents double-counting when orders have multiple payment records (e.g., split MoMo + Cash)                                                                                                                                                                               |
| `app/Services/Analytics/AnalyticsService.php` | Added explicit `whereNull('orders.deleted_at')`                                                                                                                       | New query bypasses Eloquent SoftDeletes scope, needs manual soft-delete check                                                                                                                                                                                              |

### Decisions

- **Decision**: Rewrite `getStaffSalesMetrics()` from scratch instead of patching `placedOrders()`
    - **Alternatives**: Add table prefix to `placedOrders()` method
    - **Rationale**: `placedOrders()` is used by other analytics methods — adding table prefix there would require auditing all callers. Building a fresh query with explicit prefix is safer and self-contained.
- **Decision**: Use `payments.amount` instead of `orders.total_amount` for per-method SUMs
    - **Rationale**: An order with multiple payments (e.g., split MoMo + Cash) would double-count the order's `total_amount` if summed per payment method.
- **Decision**: Merge `cash_on_delivery` into `cash` bucket
    - **Rationale**: `cash_on_delivery` is a branch payment setting key, not a fundamentally different payment flow. In the payments table, the money is still cash.

### Current State

- **Staff Sales endpoint**: Returns correct staff rows with per-method breakdowns including `manual_momo_total`/`manual_momo_count`
- **Verified**: Branch 40 returned 6 staff rows with accurate payment breakdowns
- **Cash bucket**: Includes both `cash` and `cash_on_delivery` payment records
- **Payment SUMs**: Use `payments.amount` for accuracy with split payments
- **Soft deletes**: Manually excluded via `whereNull('orders.deleted_at')`
- **Deployed**: Committed and pushed to `master`

### Cross-Repo Impact

| File (Frontend repo)                     | Change                                                                                                                                            | Impact                                        |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| `lib/api/services/branch.service.ts`     | Added `manual_momo_total` and `manual_momo_count` to `StaffSalesRow` interface                                                                    | TypeScript types match new API response shape |
| `app/staff/manager/staff-sales/page.tsx` | Added Direct MoMo card (HandCoinsIcon, orange-600), updated grid to 5 columns, added `manualMomo` to totals reducer, conditional grand total line | UI renders new `manual_momo` data             |

### Pending / Follow-up

- My Sales page 200-order cap (deferred to future session)
- Audit other `placedOrders()` callers for potential similar ambiguous column issues in complex JOIN scenarios
- Repository redirect warning on push (GitHub repo moved from somdarv to Saharabase-Technologies — cosmetic, pushes succeed)

---

## [2026-04-12] Session: 13-Issue Audit Fix

### Intent

Address 13 production issues across analytics, customer sorting, and staff sales data — improving data accuracy for payment labels, order type splits, top customer filtering, average items supporting stats, and staff sales attribution.

### Changes Made

| File                                              | Change                                                                                                                  | Reason                                                                                                  |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/CustomerController.php` | Fixed PostgreSQL sort: changed `COALESCE(total_spend, 0) DESC` to `total_spend DESC NULLS LAST`                         | PostgreSQL-native NULLS LAST is more idiomatic and correct than COALESCE workaround                     |
| `app/Services/Analytics/AnalyticsService.php`     | Separated `cash` and `cash_on_delivery` into distinct payment method labels                                             | Cash and Cash on Delivery are different payment methods — merging them misrepresented revenue breakdown |
| `app/Services/Analytics/AnalyticsService.php`     | Added dynamic order type split with label, percentage, and revenue per type (delivery, pickup, dine_in, etc.)           | Previously only had a static delivery vs pickup binary — missed dine_in and other types                 |
| `app/Services/Analytics/AnalyticsService.php`     | Top customers now filtered by fulfilled orders only (completed/delivered status)                                        | Top customers should reflect actual completed business, not pending/cancelled orders                    |
| `app/Services/Analytics/AnalyticsService.php`     | Added supporting stats for average items per order: `single_item_orders_pct`, `multi_item_orders`, `max_items_in_order` | Enriches the avg items metric with actionable context                                                   |
| `app/Services/Analytics/AnalyticsService.php`     | Staff sales: removed `whereNotNull` filter, used left joins + `COALESCE` for "Unassigned" bucket                        | Historic orders without `assigned_employee_id` were silently excluded — now shown under "Unassigned"    |

### Decisions

- **Decision**: Cash and Cash on Delivery are separate payment methods
    - **Rationale**: They represent different payment flows — cash is paid at counter, cash_on_delivery is paid on delivery. Merging them obscured operational data.
- **Decision**: Top customers metric uses fulfilled orders (completed/delivered) across all portals
    - **Rationale**: Pending, cancelled, or refunded orders don't represent real customer value. Fulfilled orders are the canonical measure of customer contribution.
- **Decision**: Staff sales includes "Unassigned" bucket via left joins + COALESCE
    - **Alternatives**: Continue excluding unassigned orders; backfill historical data
    - **Rationale**: Excluding orders silently distorts total revenue attribution. The "Unassigned" bucket makes the gap visible and accountable without requiring a data migration.
- **Decision**: Use `total_spend DESC NULLS LAST` instead of `COALESCE(total_spend, 0) DESC`
    - **Rationale**: PostgreSQL-native syntax, cleaner and avoids potential index issues with COALESCE wrapping.

### Current State

- **Customer sort**: Uses PostgreSQL `NULLS LAST` for proper NULL handling
- **Payment methods**: Cash and Cash on Delivery are separate labels in analytics
- **Order type split**: Dynamic breakdown with label/percentage/revenue per order type
- **Top customers**: Filtered to fulfilled orders only (completed/delivered)
- **Avg items per order**: Enhanced with 3 supporting stats
- **Staff sales**: Includes all orders with "Unassigned" bucket for historic data without assigned employee
- **Deployed**: Commit `c6cfb4a` merged to `master`

### Cross-Repo Impact

| File (Frontend repo)                    | Change                                                                                                                             | Impact                                         |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| `app/admin/analytics/page.tsx`          | Fixed WeeklyRevenueComparison, added Last Week period, enhanced AvgItemsPerOrder, dynamic order type donut, updated customer title | UI reflects new backend data fields            |
| `app/partner/analytics/page.tsx`        | Updated customer title, dynamic order type split                                                                                   | Partner portal aligned with new analytics data |
| `app/staff/manager/analytics/page.tsx`  | Fixed custom filter button, increased pill sizing, period-aware cards                                                              | Manager analytics uses new data shape          |
| `lib/api/services/analytics.service.ts` | Updated SalesAnalytics and DeliveryPickupAnalytics types                                                                           | TypeScript types match new API response        |
| `lib/api/hooks/useAnalytics.ts`         | Added `last_week` period type                                                                                                      | New date range option                          |

### Pending / Follow-up

- Monitor staff sales "Unassigned" bucket size — if it's large, consider the historical backfill migration
- Verify dynamic order type split handles edge cases (branches with only one order type)

---

## [2026-04-10] Session: Analytics, Customer Sort, Shift Access Fixes

### Intent

Fix three backend bugs: (1) Admin analytics top customers showing no names/order counts, (2) Customer "Highest Spend" sort broken due to NULL values, (3) Manager shifts page only showing the manager's own sessions instead of all branch staff.

### Changes Made

| File                                              | Change                                                                                                                                                                                                                                                 | Reason                                                                                                                                                                         |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Services/Analytics/AnalyticsService.php`     | `getCustomerMetrics()` now eager-loads `['user', 'orders' => fn($q) => $q->latest()->limit(1)]`. Results mapped to plain arrays with `name` resolved via chain: `user.name → order.contact_name → 'Guest'`. Includes `orders_count` and `total_spend`. | Top customers returned raw Customer models without user relationship loaded — Customer model has no `name` field (name lives on User model; guest names on Order.contact_name) |
| `app/Http/Controllers/Api/CustomerController.php` | Changed `->orderByDesc('total_spend')` to `->orderByRaw('COALESCE(total_spend, 0) DESC')`                                                                                                                                                              | `withSum` produces NULL for customers with no completed/delivered orders. MySQL sorts NULLs inconsistently, breaking "Highest Spend" sort                                      |
| `app/Http/Controllers/Api/ShiftController.php`    | Updated `index()`, `getByDate()`, and `getByStaff()` with three-tier access: Admin/TechAdmin → see all shifts; Manager → see shifts within assigned branches via `$employee->branches()->pluck('branches.id')`; Regular staff → own shifts only        | Previously only Admin/TechAdmin had a bypass — all other roles (including Manager) were restricted to their own shifts via `where('employee_id', $employee->id)`               |

### Decisions

- **Decision**: Top customer name resolution uses a fallback chain: `user.name` → `order.contact_name` → `'Guest'`
    - **Rationale**: Registered customers have a User record. Guest customers only have a name on their Order. Some have neither. The chain covers all cases.
- **Decision**: Manager shift access scoped to their assigned branches (not all branches)
    - **Rationale**: Managers should only see shifts in branches they manage. Admin/TechAdmin retain full visibility.
- **Decision**: Use `COALESCE(total_spend, 0)` rather than defaulting NULL in the model
    - **Rationale**: Fixing at query level is more explicit and doesn't affect other uses of `total_spend`.

### Current State

- **Analytics top customers**: Returns name, orders_count, total_spend as plain arrays — frontend can display immediately
- **Customer sort**: NULL spend values sorted as 0 (bottom of descending order)
- **Shift access**: Manager role sees all branch staff shifts across `index`, `getByDate`, and `getByStaff` endpoints

### Cross-Repo Impact

| File (Frontend repo)                   | Change                                                          | Impact                                   |
| -------------------------------------- | --------------------------------------------------------------- | ---------------------------------------- |
| `app/admin/analytics/page.tsx`         | Top customers reduced to 5, added Last Month + Lifetime periods | UI matches new backend data shape        |
| `lib/api/hooks/useAnalytics.ts`        | Added `last_month` and `lifetime` period types                  | New date ranges for admin analytics      |
| `app/staff/manager/analytics/page.tsx` | Full period-driven rewrite with 7 date range options            | Manager analytics now has rich filtering |
| Multiple ordering surfaces             | Added `is_available: true` to menu fetchers                     | Menu toggle now works end-to-end         |
| `app/staff/manager/settings/page.tsx`  | Branch status accounts for operating hours                      | Correct open/closed display              |
| `app/globals.css`                      | Global slim scrollbar styling                                   | UI polish                                |

### Pending / Follow-up

- Consider backfilling `NULL` `total_spend` values in the database for cleaner queries
- Monitor shift query performance with branch-scoped filtering

---

## [2026-04-09] Session: Manager Dashboard & Shifts Bug Fixes (Backend)

### Intent

Fix two backend bugs surfaced during manager dashboard testing: (1) Revenue chart showing Sunday bar as flat/zero, (2) Staff Sales section showing empty because `assigned_employee_id` was never being set for online orders.

### Changes Made

| File                                          | Change                                                                                                                                                                       | Reason                                                                                                                                                                                                                                                                                         |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Services/Analytics/AnalyticsService.php` | Added `use Carbon\Carbon;` import. Changed `getBranchRevenueChart()` to use `startOfWeek(Carbon::SUNDAY)` and `endOfWeek(Carbon::SATURDAY)` instead of default Monday start. | Carbon's `startOfWeek()` defaults to Monday. The business week starts Sunday, so Sunday was always the "last" day with no data yet — its bar was perpetually flat.                                                                                                                             |
| `app/Services/OrderManagementService.php`     | Changed auto-assignment condition from `$status === 'accepted'` to `in_array($status, ['accepted', 'preparing'])`.                                                           | Online orders often skip the `accepted` status, going directly to `preparing`. Since auto-assignment only triggered on `accepted`, `assigned_employee_id` was never set for these orders. The Staff Sales query filters on `whereNotNull('assigned_employee_id')`, resulting in empty results. |

### Decisions

- **Decision**: Staff auto-assignment triggers on both `accepted` AND `preparing` transitions
    - **Alternatives**: Trigger on all non-terminal status changes; backfill existing orders
    - **Rationale**: `accepted` and `preparing` are the two entry points into the active order flow. Triggering on all transitions would be redundant and could overwrite manual assignments. Covers both manual acceptance and direct-to-preparing flows.
- **Decision**: Week starts on Sunday (`Carbon::SUNDAY`) for revenue chart
    - **Alternatives**: Keep Monday start matching Carbon default; make it configurable
    - **Rationale**: Matches business convention — the restaurant week runs Sunday through Saturday. A configurable setting adds complexity with no current need.
- **Decision**: Do NOT retroactively fix existing orders without `assigned_employee_id`
    - **Rationale**: Determining the correct staff member for historical orders is ambiguous (who actually handled it?). The fix applies going forward. A backfill migration could be considered separately if historical accuracy matters.

### Cross-Repo Impact

| File (Frontend repo)                   | Change                                                                                                                                                                                                    | Impact                                                                |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `app/staff/manager/dashboard/page.tsx` | Added `cancel_requested` to `STATUS_STYLES`, added info block for cancel-requested orders, disabled cancel button when status is `cancel_requested`, added `cancel_requested` to active orders KPI filter | Fixes cancel "double-fire" 422 error and corrects Active Orders count |
| `app/staff/manager/shifts/page.tsx`    | Full rewrite (~480 lines) to match `MyShiftsView` layout pattern — hero card, today's summary, collapsible calendar, extracted `ShiftCard` component                                                      | Visual consistency between staff and manager shift views              |

### Current State

- **Revenue Chart**: Week runs Sunday–Saturday. Sunday bar now shows actual data instead of being perpetually flat.
- **Staff Sales**: `assigned_employee_id` is now set when orders transition to either `accepted` or `preparing`. Staff Sales query will return results going forward.
- **Limitation**: Historical orders placed before this fix that skipped `accepted` still have `NULL` `assigned_employee_id` and won't appear in Staff Sales.

### Pending / Follow-up

- Consider a one-time data migration to backfill `assigned_employee_id` for historical orders if staff sales reporting accuracy for past data is important
- Monitor staff sales data over the next few days to confirm the fix is working as expected

---

## [2026-04-09] Session: WebSocket Broadcast & POS Error UX for Branch Access

### Intent

Make branch extended access toggles propagate in real-time to all connected POS/KDS/Order Manager clients via WebSocket broadcast, and improve the POS error UX when an order is rejected because the branch is closed.

### Changes Made

| File                                                     | Change                                                                                                                                                                                                                                                                              | Reason                                                                                       |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `app/Events/BranchAccessUpdatedEvent.php`                | **NEW** — Created `BranchAccessUpdatedEvent` implementing `ShouldBroadcast`. Broadcasts on `orders.branch.{branch_id}` private channel with broadcast name `.branch.access.updated`. Payload: `branch_id`, `extended_staff_access`, `extended_order_access`, `staff_access_allowed` | Real-time propagation of access changes to all staff clients listening on the branch channel |
| `app/Http/Controllers/Api/BranchController.php`          | Added `use App\Events\BranchAccessUpdatedEvent` import. Both `toggleExtendedStaffAccess()` and `toggleExtendedOrderAccess()` now dispatch `BranchAccessUpdatedEvent::dispatch($branch->fresh())` after updating the branch                                                          | Triggers WebSocket broadcast when admin toggles access                                       |
| `app/Http/Controllers/Api/CheckoutSessionController.php` | Updated branch-closed error response in `posStore()` to include `code: 'branch_closed'` field. Changed message to user-friendly: "This branch is currently closed. To place orders after hours, ask an administrator to enable extended order access from the admin settings."      | Frontend can distinguish branch-closed errors from generic 422s and show appropriate UI      |

### Decisions

- **Decision**: Reuse existing `orders.branch.{branch_id}` channel instead of creating a new channel
    - **Alternatives**: Create a dedicated `branch.access.{id}` channel
    - **Rationale**: Staff are already subscribed to `orders.branch.{id}` for order updates. Reusing it means zero additional auth/subscription logic needed. The `.branch.access.updated` broadcast name is completely separate from `.order.updated` — no interference.
- **Decision**: Add a `code` field (`branch_closed`) to the 422 error response
    - **Alternatives**: Use HTTP status code differentiation, use a different error message format
    - **Rationale**: HTTP status alone (422) is shared with other validation errors. A `code` field lets the frontend programmatically identify the specific error type and show contextual UI (modal vs toast).

### Cross-Repo Impact

| File (Frontend repo)                          | Change                                                                                                                    | Impact                                                        |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| `app/components/providers/BranchProvider.tsx` | Added WebSocket listener for `.branch.access.updated` on branch channel — invalidates `['branches']` query cache on event | Instant branch data refresh when admin toggles access         |
| `app/pos/terminal/page.tsx`                   | Added `branchClosedNotice` state and modal — detects `code === 'branch_closed'` from API response                         | POS shows a modal instead of a toast for branch-closed errors |

### Current State

- **`BranchAccessUpdatedEvent`**: New broadcast event, broadcasts on existing private channel with separate event name
- **`BranchController`**: Both toggle methods dispatch the broadcast event after update
- **`posStore()` error**: Returns `code: 'branch_closed'` with user-friendly message when branch is closed and extended order access is disabled
- **Deployed**: Committed on `manager-staff-fixes`, merged to `master` and `beta`

### Pending / Follow-up

- Consider adding tests for the broadcast event dispatch in toggle endpoints
- Monitor WebSocket event delivery in production to ensure Reverb handles the broadcast correctly

---

## [2026-04-09] Session: Branch Extended Access — After-Hours Staff System Access

### Intent

Add admin-controlled "Extended Access" toggles per branch so staff can continue using POS, Kitchen Display System (KDS), and Order Manager after the branch closes (e.g., for sales reconciliation). A second toggle allows placing new POS orders during extended access (for special/after-hours orders). Customers continue seeing the branch as closed.

### Changes Made

| File                                                                                      | Change                                                                                                                                                                                                                      | Reason                                                                                                    |
| ----------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `database/migrations/2026_04_09_015228_add_extended_access_columns_to_branches_table.php` | **NEW** — Adds `extended_staff_access` (bool, default false) and `extended_order_access` (bool, default false) to `branches` table                                                                                          | Persistent per-branch flags for extended access                                                           |
| `app/Models/Branch.php`                                                                   | Added `extended_staff_access`, `extended_order_access` to `$fillable`, `casts()`, and activity log. Added `isStaffAccessAllowed()` (open OR extended_staff_access) and `isExtendedOrderAllowed()` (both flags true) methods | Business logic for staff access checks                                                                    |
| `app/Http/Resources/BranchResource.php`                                                   | Added `extended_staff_access`, `extended_order_access`, and computed `staff_access_allowed` to API response                                                                                                                 | Frontend needs these flags to gate access                                                                 |
| `app/Http/Controllers/Api/BranchController.php`                                           | Added `toggleExtendedStaffAccess()` and `toggleExtendedOrderAccess()` methods. Staff toggle automatically disables order access when disabled. Order toggle requires staff access to be enabled first.                      | Admin control endpoints                                                                                   |
| `app/Http/Controllers/Api/CheckoutSessionController.php`                                  | Added `isCurrentlyOpen()` + `isExtendedOrderAllowed()` check to `posStore()`. Previously POS had no server-side branch-open check.                                                                                          | Server-side enforcement: POS orders blocked when branch is closed UNLESS extended order access is enabled |
| `routes/admin.php`                                                                        | Added `PATCH branches/{branch}/toggle-extended-staff-access` and `PATCH branches/{branch}/toggle-extended-order-access` routes under `manage_branches` permission                                                           | Route registration for toggle endpoints                                                                   |

### Decisions

- **Decision**: Two separate toggles (Staff Access + Order Access) instead of one
    - **Rationale**: Restaurant business is dynamic — sometimes staff need access for reconciliation only (no new orders), other times they need to place special after-hours orders. Separate toggles give fine-grained control.
- **Decision**: Extended access is a persistent per-branch flag, NOT tied to operating hours schedule
    - **Rationale**: Unlike the existing `manual_override_open` (which is per-day and resets), extended access persists until admin explicitly turns it off.
- **Decision**: Customers still see the branch as closed during extended access
    - **Rationale**: `is_open` (customer-facing) is still computed from operating hours. `staff_access_allowed` is a separate computed field for staff systems only.
- **Decision**: Added server-side POS checkout block
    - **Rationale**: `posStore()` previously had NO `isCurrentlyOpen()` check (only frontend gate). Now has proper server-side enforcement with extended order access bypass.
- **Decision**: Disabling Staff Access auto-disables Order Access
    - **Rationale**: Order Access logically depends on Staff Access. Can't place orders if you can't access the system.

### Cross-Repo Impact

| File (Frontend repo)                          | Change                                                                                               | Impact                                          |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| `types/api.ts`                                | Added `extended_staff_access`, `extended_order_access`, `staff_access_allowed` to `Branch` interface | TypeScript type matches `BranchResource` output |
| `app/components/providers/BranchProvider.tsx` | Maps API fields to camelCase frontend model                                                          | Data layer alignment                            |
| `lib/api/services/branch.service.ts`          | Added `toggleExtendedStaffAccess()` and `toggleExtendedOrderAccess()` service methods                | Calls admin toggle routes                       |
| `app/pos/terminal/page.tsx`                   | Branch-closed guard now checks `staffAccessAllowed`                                                  | POS extended access bypass                      |
| `app/kitchen/layout-client.tsx`               | Added full branch-closed gate (NEW — KDS had no gate before)                                         | KDS extended access bypass                      |
| `app/order-manager/page.tsx`                  | Existing branch-closed guard updated to check `staffAccessAllowed`                                   | Order Manager extended access bypass            |
| `app/admin/settings/page.tsx`                 | New "Branch Access" tab with per-branch toggle UI                                                    | Admin control UI                                |

### Current State

- **Branch model**: Has `extended_staff_access` and `extended_order_access` boolean columns (default false)
- **`isStaffAccessAllowed()`**: Returns true if branch is open OR `extended_staff_access` is true
- **`isExtendedOrderAllowed()`**: Returns true only if BOTH `extended_staff_access` AND `extended_order_access` are true
- **`BranchResource`**: Serializes both raw flags + computed `staff_access_allowed`
- **`posStore()`**: Now validates branch is open or extended order access is enabled (server-side enforcement)
- **Routes**: Two new `PATCH` routes under `manage_branches` permission
- **Migration**: Created, needs to be run in production
- **Branch**: `menu-audit`

### Pending / Follow-up

- Run migration `2026_04_09_015228_add_extended_access_columns_to_branches_table.php` in production
- ~~Consider broadcasting a WebSocket event when extended access is toggled so POS/KDS/OM update in real-time~~ — **DONE** in [2026-04-09] WebSocket Broadcast & POS Error UX session
- Consider adding tests for `isStaffAccessAllowed()`, `isExtendedOrderAllowed()`, and the `posStore()` branch-open check

### Future Enhancement: Auto-Expire Extended Access

**Problem**: If an admin enables extended staff/order access and forgets to disable it, staff could have access indefinitely (overnight, next day, etc.).

**Proposed Implementation**:

1. **Migration**: Add `extended_access_expires_at` (nullable datetime) column to `branches` table
2. **Admin UI**: When enabling extended access, optionally set an expiry duration (e.g., 2h, 4h, 8h, custom) or leave as "Until manually disabled"
3. **Model Logic**: Update `isStaffAccessAllowed()` to check: `extended_staff_access && (extended_access_expires_at === null || extended_access_expires_at > now())`
4. **Scheduled Command**: `php artisan schedule:run` — a scheduled task that runs every minute to auto-disable expired extended access: set `extended_staff_access = false, extended_order_access = false, extended_access_expires_at = null` where `extended_access_expires_at <= now()`
5. **Alternative**: Instead of a scheduled command, just check the expiry in `isStaffAccessAllowed()` and let the next API call handle it lazily. The toggle UI would still show it as "on" until the admin views the page (which refetches), but access would be blocked server-side.
6. **Notification**: Optionally notify the admin (or broadcast via Reverb) 15 minutes before expiry so they can extend if needed.

---

## [2026-04-09] Session: Master Orchestrator Agent Created (Cross-Repo Reference)

### Intent

Record that a Master Orchestrator agent was created in the **frontend repo** (`cedibites/`) that coordinates all 7 specialized agents across both repos.

### Changes Made

No changes in this repo. The agent file lives in the frontend repo.

| File (Frontend repo)                          | Change                              | Reason                                         |
| --------------------------------------------- | ----------------------------------- | ---------------------------------------------- |
| `.github/agents/master-orchestrator.agent.md` | **NEW** — Master Orchestrator agent | Central coordinator for all specialized agents |

### Cross-Repo Impact

| File (This repo)                                             | Relationship                                                                                       |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| `.github/instructions/Engineering-practices.instructions.md` | Critical engineering rules are embedded directly in the orchestrator's Section VII (Quality Gates) |
| `AGENTS.md`                                                  | Backend agent/boost definitions referenced by the orchestrator's agent registry                    |

### What This Means for Backend Work

- The orchestrator can coordinate multi-repo tasks (e.g., "add a new field" triggers backend migration + API resource + frontend type + UI component)
- It enforces backend engineering practices (SOLID, service pattern, Eloquent conventions) as quality gates
- Backend agents (Order Auditor, Analytics Auditor, etc.) can escalate cross-cutting concerns to the orchestrator
- Pinned to Claude Opus 4 for strong reasoning on complex orchestration

### Current State

- **No backend code changes** — this is an agent tooling addition in the frontend repo
- **Branch**: `menu-audit`

---

## [2026-04-09] Session: Production 500 Fix — Encrypted Cast on Pre-Existing Plain-Text Ghana Card IDs

### Intent

Investigate and fix a production outage where both the admin staff page (`/admin/staff`) and partner/branch manager staff page (`/partner/staff`) showed "0 staff found" because `GET /admin/employees` returned **500 Internal Server Error**.

### Investigation

- IAM Auditor traced the full request chain: both frontend pages use `useEmployees` hook → `employeeService.getEmployees()` → `GET /admin/employees?per_page=200`.
- Backend code worked perfectly locally (15 employees returned).
- Production API was alive (public endpoints responded), but authenticated employee endpoints returned 500.
- 13 diagnostic SQL queries were run against the production PostgreSQL database.

### Root Cause

- **3 employee records had `ghana_card_id` stored as plain text** (e.g., `GHA-723801`, `GHA-724827`, `GHA-726806`).
- These values were written **before** the `encrypted` cast was added to the Employee model (commit `dc54239`, April 7).
- When Laravel tried to load employees, `Crypt::decrypt('GHA-723801')` threw `DecryptException`, crashing the entire paginated query and returning 500 for all users.

### Fix Applied (Production Database)

| Action                                                                          | Detail                               | Reason                                                                                                           |
| ------------------------------------------------------------------------------- | ------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| Extracted plain-text Ghana Card IDs                                             | Screenshot taken for safekeeping     | Preserve PII values before nulling                                                                               |
| `UPDATE employees SET ghana_card_id = NULL WHERE ghana_card_id NOT LIKE 'eyJ%'` | Nulled 3 rows with plain-text values | `eyJ` prefix identifies Laravel-encrypted values; anything else is plain text that will crash `Crypt::decrypt()` |

### Files Involved

| File                                              | Role in Incident                                                                       |
| ------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `app/Models/Employee.php`                         | `encrypted` cast on `ghana_card_id` (added in commit `dc54239`, April 7) — the trigger |
| `app/Http/Controllers/Api/EmployeeController.php` | `index()` method — endpoint that crashed                                               |
| `app/Http/Resources/EmployeeResource.php`         | Serializes employee data including encrypted fields                                    |

### Decisions

- **Decision**: Null plain-text PII values rather than attempt SQL-level encryption
    - **Alternatives**: Encrypt values directly in SQL using Laravel's APP_KEY and encryption format
    - **Rationale**: Laravel's `encrypted` cast uses `APP_KEY` + a specific serialization format (`encrypt()`). Reproducing this in raw SQL is fragile and error-prone. Safer to null and re-enter through the admin UI.
- **Decision**: Values will be re-entered through the admin UI to be stored with proper encryption
    - **Rationale**: The UI flows through Laravel's model layer, which applies the `encrypted` cast automatically on write.

### Lessons Learned

- When adding `encrypted` casts to existing model fields, a **data migration** must accompany the schema change to encrypt any existing plain-text values.
- The `encrypted` cast fails hard (500 `DecryptException`) on non-encrypted data — there is **no graceful fallback**. One bad row crashes the entire paginated query.
- Consider adding a `try/catch` around encrypted field access in `EmployeeResource` or a boot-time data integrity check to prevent a single corrupt row from taking down an entire listing endpoint.

### Cross-Repo Impact

| File (Frontend repo)                   | Impact                                                                    |
| -------------------------------------- | ------------------------------------------------------------------------- |
| `lib/api/services/employee.service.ts` | No code change — service was correct; issue was backend 500               |
| `lib/api/hooks/useEmployees.ts`        | No code change — hook was correct                                         |
| `app/admin/staff/page.tsx`             | No code change — page rendered "0 staff found" because API returned error |
| `app/partner/staff/page.tsx`           | No code change — same symptom                                             |

Frontend required **no changes** — the issue was entirely a backend data integrity problem.

### Current State

- **Staff pages**: Both admin and partner staff pages loading correctly in production
- **Employee data**: 3 employees have `ghana_card_id = NULL` — values need to be re-entered via admin UI
- **Encrypted cast**: Still active on `Employee.ghana_card_id` — all future writes go through Laravel encryption
- **No code changes committed** — this was a production database fix only
- **Branch**: `menu-audit`

### Pending / Follow-up

- Re-enter the 3 nulled Ghana Card IDs through the admin UI (GHA-723801, GHA-724827, GHA-726806)
- Consider adding a `try/catch` in `EmployeeResource` around encrypted field access to prevent one bad row from crashing the entire listing
- Consider a data migration strategy for future `encrypted` cast additions: migrate existing plain-text values in the same deployment
- Audit other models for `encrypted` casts that may have the same pre-existing plain-text data risk

---

## [2026-04-08] Session: Deploy Pipeline Seeding, Response Macro Enhancement, TechAdmin Seeder Removal

### Intent

Automate permission and role seeding on every production deploy so new permissions/roles are always applied without manual SSH. Fix the `response()->success()` macro to support an optional message parameter. Remove the now-unnecessary `TechAdminSeeder`.

### Changes Made

| File                                             | Change                                                                                                                                                                       | Reason                                                                                                                                            |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `.github/workflows/deploy.yml`                   | Added `php artisan db:seed --class=PermissionSeeder --force` and `php artisan db:seed --class=RoleSeeder --force` to the production deploy pipeline                          | Ensures new permissions and roles are automatically applied on every deploy — no manual SSH required                                              |
| `app/Providers/ResponseMacroServiceProvider.php` | Enhanced the `success()` response macro to accept an optional `?string $message` parameter; when provided, a `message` key is included in the JSON response alongside `data` | Several controller methods were passing a message string as the second argument to `response()->success()` but the macro was silently ignoring it |
| `database/seeders/TechAdminSeeder.php`           | **DELETED** — Removed entirely                                                                                                                                               | TechAdmin user creation is now handled differently; seeder was no longer needed. Deploy workflow reference to it was also removed.                |

### Decisions

- **Decision**: Run `PermissionSeeder` and `RoleSeeder` on every deploy rather than only on initial setup
    - **Rationale**: Seeders are idempotent (they use `syncPermissions`/`syncRoles`). Running them on deploy ensures new permissions added in code are immediately available in production without manual intervention. This was a pain point discovered in the April 7 session where `sales_staff` role was missing from the database.
- **Decision**: Delete `TechAdminSeeder` rather than deprecate
    - **Rationale**: The seeder is no longer referenced anywhere and the user creation it handled is done through a different mechanism now. Dead code removal.
- **Decision**: `success()` macro parameter is optional (`?string $message = null`) with conditional inclusion
    - **Rationale**: Backward-compatible — existing callers passing only `$data` are unaffected. Callers passing a message now get it included in the response.

### Cross-Repo Impact

No frontend changes required — these are backend infrastructure changes (deploy pipeline, response format enhancement, dead code removal).

### Current State

- **Deploy pipeline**: Production deploys now automatically seed permissions and roles via `PermissionSeeder` and `RoleSeeder`
- **Response macro**: `response()->success($data, 'Optional message')` now works correctly, including the message in the JSON response
- **TechAdminSeeder**: Deleted — no longer exists in the codebase
- **Commit**: `b652396` on `menu-audit`, merged to `master` at `bb6c8ca`, synced to `beta` at `7b9584e`
- **Branch**: `menu-audit` = `master`

### Pending / Follow-up

- Verify that the next production deploy runs both seeders successfully
- Audit other response macro methods (`error()`, `unauthorized()`) for similar silent parameter issues

---

## [2026-04-08] Session: Hubtel Callback IP Fix, Deploy Script Fixes, Role Permission Update

### Intent

Fix a critical production bug where all Hubtel payment callbacks were being silently rejected (403) due to a fail-closed IP allowlist with no IPs configured. Also fix deploy scripts that couldn't pull latest code due to divergent branches, and grant `ManageShifts` permission to additional roles.

### Changes Made

#### Hubtel Callback IP Check Fix (CRITICAL)

| File                                             | Change                                                                                                                                                                                        | Reason                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Http/Controllers/Api/PaymentController.php` | Reverted `isAllowedCallbackIp()` from fail-closed to fail-open behavior — when `HUBTEL_ALLOWED_IPS` env var is empty/unset, all IPs are allowed; when configured, strict allowlisting applies | **CRITICAL BUG**: During the April 3rd refactor (commit `19c5161`), the method was changed from fail-open to fail-closed. Since `HUBTEL_ALLOWED_IPS` was never configured in production, ALL Hubtel payment callbacks (both RMP and Standard) were silently rejected with 403. MoMo payments completed on customer's end but checkout sessions stayed at `payment_initiated` — no orders created, no receipts printable. |

#### Deploy Script Fixes

| File                                | Change                                                                                                                          | Reason                                                                                                                                             |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `.github/workflows/deploy.yml`      | Changed `git pull origin master` to `git fetch origin master && git reset --hard origin/master`; removed `php artisan optimize` | `git pull` failed on divergent branches, preventing production from receiving code updates. `php artisan optimize` was causing route cache issues. |
| `.github/workflows/deploy-beta.yml` | Changed `git pull origin master` to `git fetch origin master && git reset --hard origin/master`                                 | Same divergent branch fix as production deploy script                                                                                              |

#### Role Permission Update

| File                              | Change                                                                     | Reason                                                     |
| --------------------------------- | -------------------------------------------------------------------------- | ---------------------------------------------------------- |
| `database/seeders/RoleSeeder.php` | Added `ManageShifts` permission to `sales_staff` and `order_manager` roles | Sales and Order Manager roles need shift management access |

### Decisions

- **Decision**: Fail-open over fail-closed for IP allowlisting when `HUBTEL_ALLOWED_IPS` is empty
    - **Alternatives**: Keep fail-closed and immediately configure IPs in production
    - **Rationale**: Callback URL + payload structure provide reasonable protection, and this is how it ran successfully before April 3rd. Low-medium risk. Configuring IPs requires getting Hubtel's callback server IPs from RSE first.
- **Decision**: Use `git reset --hard origin/master` in deploy scripts instead of `git pull`
    - **Alternatives**: `git pull --rebase`, manual conflict resolution
    - **Rationale**: Deploy target should always mirror the remote branch exactly. `reset --hard` prevents future divergence issues and ensures predictable deployments.

### Cross-Repo Impact

- No frontend changes required — these are backend infrastructure and configuration fixes.

### Current State

- **Payment callbacks**: Hubtel callbacks now accepted in production (fail-open when no IPs configured)
- **Deploy pipeline**: Both production and beta deploy scripts use `git fetch + reset --hard` for reliable code delivery
- **Route caching**: `php artisan optimize` removed from production deploy — no more route cache issues
- **Roles**: `sales_staff` and `order_manager` now have `ManageShifts` permission
- **Branch**: `menu-audit`

### Pending / Follow-up

- **TODO**: Get Hubtel's callback server IPs from RSE and configure `HUBTEL_ALLOWED_IPS` in production `.env` to enable strict IP allowlisting
- Verify checkout sessions that were stuck at `payment_initiated` during the outage — may need manual reconciliation
- Monitor payment callback success rate after fix

---

## [2026-04-07] Session: Analytics Engine Post-Overhaul Bug Fix

### Intent

Fix bugs, silent data issues, N+1 queries, and frontend-backend contract mismatches discovered during a post-overhaul audit of the analytics engine. The most critical issue was a silent date filter bug that caused staff sales data to return unfiltered results.

### Changes Made

#### Query Builder Fix

| File                                               | Change                                                                                           | Reason                                                                                                         |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------- |
| `app/Services/Analytics/AnalyticsQueryBuilder.php` | `activeOrders()` refactored to use shared `applyFilters()` method instead of inline filter logic | Was missing `branch_ids` and `date_from`/`date_to` support — inconsistent with all other query builder methods |

#### AnalyticsService Fixes

| File                                          | Change                                                                                                                                                                                     | Reason                                                                                                 |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------ |
| `app/Services/Analytics/AnalyticsService.php` | `getEmployeeBranchStats()` — replaced raw `Order::whereIn()` for `pending_orders`/`preparing_orders` with `$this->queryBuilder->activeOrders()`                                            | Was bypassing the query builder, missing filter application                                            |
| `app/Services/Analytics/AnalyticsService.php` | `getStaffSalesMetrics()` — refactored from loading all orders into PHP memory + iterating for payment method breakdowns to a single DB query using conditional `SUM`/`COUNT DISTINCT CASE` | Eliminated N+1 and memory issues at scale                                                              |
| `app/Services/Analytics/AnalyticsService.php` | `getStaffSalesMetrics()` return type changed from `Collection` to `array`                                                                                                                  | Controller just passes result to `response()->success()` which handles both — `array` is more explicit |
| `app/Services/Analytics/AnalyticsService.php` | Added `getBranchTodayStatsBulk(array $branchIds)` method — computes revenue and order counts for all branches in 2 GROUP BY queries                                                        | Supports new bulk dashboard endpoint to avoid O(N) queries                                             |

#### Controller Fixes

| File                                                    | Change                                                                                                                                                                                                                  | Reason                                                             |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `app/Http/Controllers/Api/BranchController.php`         | **CRITICAL BUG FIX**: `staffSales()` was passing `start_date`/`end_date` filter keys but query builder expects `date_from`/`date_to` — date filtering was silently ignored, returning ALL dates instead of selected day | Silent data bug — endpoint returned unfiltered data with no errors |
| `app/Http/Controllers/Api/AdminDashboardController.php` | Refactored from calling `getBranchTodayStats()` per branch in a loop (2N queries for N branches) to using new `getBranchTodayStatsBulk()` method (2 queries total)                                                      | N+1 query elimination for dashboard                                |

#### Frontend Contract Alignment (cedibites repo)

| File                                    | Change                                                                                                                                                                                              | Reason                                                     |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| `lib/api/services/analytics.service.ts` | `OrderAnalytics`: Added `active_orders: number`, changed `average_prep_time` to `number \| null`                                                                                                    | Interface mismatch with backend response                   |
| `lib/api/services/analytics.service.ts` | `DeliveryPickupAnalytics`: Added `delivery_revenue` and `pickup_revenue` fields                                                                                                                     | Missing fields from backend response                       |
| `lib/api/services/analytics.service.ts` | `SalesAnalytics`: Added `completed_orders`, `cancelled_orders`, `cancelled_revenue` fields                                                                                                          | Missing fields from backend response                       |
| `lib/api/services/analytics.service.ts` | `OrderSource`: Added `total_revenue` field                                                                                                                                                          | Missing field from backend response                        |
| `lib/api/services/branch.service.ts`    | Replaced all 5 `any`-typed manager endpoint returns with proper interfaces: `BranchStats` (9 fields), `BranchTopItem` (5 fields), `BranchRevenueChartPoint` (4 fields), `StaffSalesRow` (12 fields) | Eliminated `any` types — compile-time contract enforcement |

### Decisions

- **Decision**: `getStaffSalesMetrics()` returns `array` instead of `Collection`
    - **Rationale**: Controller passes result directly to `response()->success()` which serializes both; `array` is more explicit and avoids unnecessary Collection overhead
- **Decision**: Dashboard uses new `getBranchTodayStatsBulk()` method instead of per-branch calls
    - **Rationale**: Reduces 2N queries to 2 queries total — critical for dashboards with many branches
- **Decision**: All query builder methods now consistently use `applyFilters()` — no more inline filter logic
    - **Rationale**: Single source of truth for filter application prevents filter key mismatches and ensures new filters automatically apply everywhere

### Cross-Repo Impact

| File (Frontend repo)                    | Change                                             | Triggered By                        |
| --------------------------------------- | -------------------------------------------------- | ----------------------------------- |
| `lib/api/services/analytics.service.ts` | 4 interfaces updated with missing/corrected fields | Backend analytics response audit    |
| `lib/api/services/branch.service.ts`    | 4 new typed interfaces replace `any` returns       | Branch manager endpoint type safety |

### Current State

- **Analytics engine**: Fully unified — all controllers are thin wrappers, no inline computation anywhere
- **Query builder**: All methods use `applyFilters()` consistently — `activeOrders()` was the last holdout
- **Staff sales**: Date filtering works correctly (`date_from`/`date_to` keys aligned)
- **Dashboard**: Bulk stats method eliminates O(N) per-branch queries
- **Frontend types**: Zero `any` types in analytics/branch service files; all interfaces match backend responses
- **Tests**: All 74 pass (1 pre-existing IAM SecurityHardeningTest failure, unrelated)
- **Pint**: Clean
- **TypeScript**: Zero errors in modified files
- **Branch**: `menu-audit`

### Pending / Follow-up

- Pre-existing `SecurityHardeningTest` failure needs investigation (unrelated to this session)
- Consider adding integration tests for analytics filter propagation (ensure `date_from`/`date_to` reach all query paths)
- Monitor staff sales endpoint in production to confirm date filtering is now correct

---

## [2026-04-07] Session: Promo System End-to-End — Migrations, Checkout Integration, Route Bug Fix

### Intent

Complete the promo system end-to-end after an audit found promos were "infrastructure-complete but customer-disconnected." Admin CRUD worked, but promos never reached customers or POS. This session added promo fields to the order/checkout schema, wired promo resolution into checkout and POS flows, and fixed a missing route that prevented promo resolution from ever working.

### Changes Made

#### Database Migrations

| File                                                                                    | Change                                                                                                             | Reason                                                     |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| `database/migrations/2026_04_07_003326_add_discount_and_promo_to_orders_table.php`      | **NEW** — Added `discount` decimal(10,2), `promo_id` FK (nullable), `promo_name` string (nullable) to orders table | Orders need to store applied promo and calculated discount |
| `database/migrations/2026_04_07_003331_add_promo_fields_to_checkout_sessions_table.php` | **NEW** — Added `promo_id` FK (nullable), `promo_name` string (nullable) to checkout_sessions table                | Checkout session carries promo data to order creation      |

#### Model & Service Updates

| File                                    | Change                                                                                                                                    | Reason                                                       |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| `app/Models/Order.php`                  | Added `discount`, `promo_id`, `promo_name` to `$fillable`; added `decimal:2` cast for `discount`; added `promo(): BelongsTo` relationship | Order ↔ Promo relationship and proper data handling          |
| `app/Models/CheckoutSession.php`        | Added `promo_id`, `promo_name` to `$fillable`                                                                                             | Checkout session stores resolved promo                       |
| `app/Services/OrderCreationService.php` | Now copies `discount`, `promo_id`, `promo_name` from checkout session → order during creation                                             | Promo data flows through the payment-first checkout pipeline |

#### Controller & Resource Updates

| File                                                     | Change                                                                                                                                                            | Reason                                                                |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `app/Http/Controllers/Api/CheckoutSessionController.php` | `store()` auto-resolves best promo via `PromoResolutionService` and calculates discount; `posStore()` does server-side promo validation when `promo_id` is passed | Customer checkout gets auto-promo; POS gets explicit promo validation |
| `app/Http/Resources/OrderResource.php`                   | Added `discount`, `promo_id`, `promo_name` to serialized output                                                                                                   | Frontend needs promo data for display                                 |

#### Route Bug Fix (Critical)

| File                | Change                                                                                                                               | Reason                                                                                                                                                                                                                                                                                                                                                                            |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `routes/promos.php` | Added `Route::post('promos/resolve', [PromoController::class, 'resolve'])` **outside** the `permission:manage_menu` middleware group | **BUG FIX**: Route was never registered! `PromoController@resolve`, `ResolvePromoRequest`, `PromoResolutionService`, and frontend `ApiPromoService.resolvePromo()` all existed — but the route binding was missing. POS `useEffect` called the API, got 404, `.catch()` silently set `activePromo=null`. Placed outside `manage_menu` gate so any authenticated user can resolve. |

### Decisions

- **Decision**: Promo resolve route placed outside `manage_menu` permission gate
    - **Rationale**: Both POS staff and customers need to resolve promos — `manage_menu` is for admin CRUD operations only
- **Decision**: `PromoResolutionService` returns the promo with highest calculated discount when multiple apply
    - **Rationale**: Best-value-for-customer approach; simpler than presenting a promo picker
- **Decision**: `posStore()` does server-side promo validation (re-resolves and compares); `store()` auto-resolves
    - **Rationale**: POS sends `promo_id` explicitly (staff sees the promo first); customer checkout auto-applies the best one
- **Decision**: `discount` stored as `decimal(10,2)` on orders table
    - **Rationale**: Monetary precision; matches `total` and other monetary columns
- **Decision**: 2 active test promos seeded: "10% off orders over ₵100" (min_order_value=100) and "20% off for new customers" (global, no minimum)
    - **Note**: The "20% off for new customers" promo currently applies to ALL users — no customer-specific filtering logic implemented yet

### Cross-Repo Impact

| File (Frontend repo)                    | Change                                                                                                                                                   | Triggered By                               |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| `types/api.ts`                          | Added `promo_id?`, `promo_name?` to Order interface                                                                                                      | OrderResource now serializes promo fields  |
| `lib/api/adapters/order.adapter.ts`     | Maps `promo_name → promoCode`                                                                                                                            | Adapter alignment with new API response    |
| `app/(customer)/checkout/page.tsx`      | Auto-resolves promo, shows green discount row with TagIcon                                                                                               | Checkout promo integration                 |
| `app/components/order/OrderDetails.tsx` | Discount + service charge display                                                                                                                        | Order detail promo display                 |
| `app/pos/terminal/page.tsx`             | Fixed: passes `cartTotal` to `resolvePromo()` (was `undefined` → subtotal=0 → ₵0.00 discount); rewrote total section to show Subtotal → Discount → Total | POS subtotal bug fix + display enhancement |
| `app/staff/new-order/context.tsx`       | Same subtotal bug fix — compute subtotal before resolve call                                                                                             | Staff new-order promo fix                  |

### Current State

- **Promo schema**: `orders` table has `discount`, `promo_id`, `promo_name`; `checkout_sessions` table has `promo_id`, `promo_name`
- **Promo flow**: End-to-end working — `PromoResolutionService` resolves → CheckoutSession stores → `OrderCreationService` copies to Order → `OrderResource` serializes
- **Route**: `POST /v1/promos/resolve` now registered and accessible to any authenticated user
- **Models**: Order has `promo()` BelongsTo relationship
- **Migrations**: Created, need to be run in production
- **Pint**: Clean
- **Branch**: `menu-audit`

### Pending / Follow-up

- Run migrations `2026_04_07_003326_*` and `2026_04_07_003331_*` in production
- "New customers" promo needs actual customer-filtering logic in `PromoResolutionService` if intended to be customer-specific
- Menu item promo badges (frontend wants "badge better than strikethrough" — deferred due to architectural complexity)
- `PromoBanner.tsx` on frontend still uses hardcoded data
- Consider adding promo usage tracking (how many times a promo was applied, total discount given)

---

## [2026-04-07] Session: Role Restructuring — `platform_admin` → `tech_admin`, Removed `super_admin`

### Intent

Simplify the role hierarchy by renaming `platform_admin` to `tech_admin` (IT personnel with full system access) and merging `super_admin` into `admin` (business owner role). The previous hierarchy had overlapping roles — `platform_admin` and `super_admin` had near-identical permissions, and `admin` was redundant with `super_admin`.

### Changes Made

| File                                                                                                   | Change                                                                                                                                                                                                                                                                               | Reason                                                       |
| ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------ |
| `app/Enums/Role.php`                                                                                   | Renamed `PlatformAdmin` → `TechAdmin` (value: `tech_admin`), removed `SuperAdmin` case. Enum now has 8 roles.                                                                                                                                                                        | Clear IT vs business separation                              |
| `database/seeders/RoleSeeder.php`                                                                      | Restructured permission assignments: `tech_admin` gets ALL permissions; `admin` loses 8 platform-specific permissions (`access_platform_admin`, `view_system_health`, `view_error_logs`, `manage_roles`, `reset_passwords`, `manage_platform`, `manage_cache`, `toggle_maintenance`) | Clean separation — business owners don't need platform tools |
| `database/migrations/2026_04_07_002415_rename_platform_admin_to_tech_admin_and_remove_super_admin.php` | **NEW** — Renames `platform_admin` role to `tech_admin` in DB, reassigns `super_admin` users to `admin`, cleans up `super_admin` permission entries, deletes `super_admin` role record                                                                                               | Safe data migration for existing users and permissions       |
| `routes/platform.php`                                                                                  | Updated role references from `platform_admin` to `tech_admin`                                                                                                                                                                                                                        | Route middleware alignment                                   |
| `routes/admin.php`                                                                                     | Updated role references — replaced `super_admin` with `admin` or `tech_admin` as appropriate                                                                                                                                                                                         | Route access control alignment                               |
| `routes/channels.php`                                                                                  | Updated broadcasting channel role checks                                                                                                                                                                                                                                             | WebSocket auth alignment                                     |
| `app/Http/Controllers/Api/Admin/PlatformController.php`                                                | Updated role/permission checks from `platform_admin` to `tech_admin`                                                                                                                                                                                                                 | Controller authorization                                     |
| `app/Http/Controllers/Api/RoleController.php`                                                          | Updated display name map and role references                                                                                                                                                                                                                                         | Role listing endpoint                                        |
| `app/Http/Controllers/Api/ShiftController.php`                                                         | Updated role references                                                                                                                                                                                                                                                              | Shift management access                                      |
| `app/Http/Controllers/Api/EmployeeOrderController.php`                                                 | Updated role references                                                                                                                                                                                                                                                              | Employee order access                                        |
| `app/Http/Controllers/Api/CheckoutSessionController.php`                                               | Updated role references                                                                                                                                                                                                                                                              | Checkout session access                                      |
| `app/Http/Controllers/Api/OrderController.php`                                                         | Updated role references                                                                                                                                                                                                                                                              | Order management access                                      |
| `app/Http/Controllers/Api/PosOrderController.php`                                                      | Updated role references                                                                                                                                                                                                                                                              | POS order access                                             |
| `app/Http/Middleware/EnsureBranchAccess.php`                                                           | Updated role checks from `super_admin`/`platform_admin` to `tech_admin`/`admin`                                                                                                                                                                                                      | Middleware authorization                                     |
| `app/Services/OrderManagementService.php`                                                              | Updated role references for order management permissions                                                                                                                                                                                                                             | Service-level authorization                                  |

### Decisions

- **Decision**: Named the role `tech_admin` over alternatives (`system_admin`, `it_admin`)
    - **Alternatives**: `system_admin` (too generic), `it_admin` (less professional)
    - **Rationale**: Concise, clear, immediately communicates "technical/IT personnel"
- **Decision**: `admin` keeps full business permissions but loses 8 platform-specific permissions
    - **Rationale**: Clean separation of concerns — business owners manage the business, IT manages the platform. No business scenario requires an admin to toggle maintenance mode or manage cache.
- **Decision**: `tech_admin` gets ALL permissions (replaces what `super_admin` had)
    - **Rationale**: IT personnel need unrestricted access for support, debugging, and platform operations
- **Decision**: Merged `super_admin` into `admin` rather than into `tech_admin`
    - **Rationale**: Most `super_admin` users were business owners, not IT. They map naturally to the `admin` role.

### Cross-Repo Impact

| File (Frontend repo)                             | Change                                                                           | Triggered By                  |
| ------------------------------------------------ | -------------------------------------------------------------------------------- | ----------------------------- |
| `types/staff.ts`                                 | `StaffRole` type updated: `platform_admin` → `tech_admin`, `super_admin` removed | Role enum changes             |
| `types/order.ts`                                 | `StaffRole` re-export updated                                                    | Role type alignment           |
| `lib/api/services/employee.service.ts`           | Role mapping simplified to 1:1 (no more collapsing `admin` → `super_admin`)      | Role hierarchy simplification |
| `app/components/providers/StaffAuthProvider.tsx` | Role routing updated for new role names                                          | Auth flow alignment           |
| 7 page/layout files                              | Role checks updated across admin, POS, order-manager, and staff portals          | Authorization UI alignment    |

### Current State

- **Role enum**: 8 roles — `tech_admin`, `admin`, `branch_manager`, `kitchen_staff`, `sales_staff`, `delivery_staff`, `cashier`, `pos_operator`
- **`tech_admin`**: Full system access (ALL permissions) — for IT personnel
- **`admin`**: Full business access minus 8 platform permissions — for business owners
- **`super_admin`**: Completely removed from the system
- **`platform_admin`**: Renamed to `tech_admin` — no longer exists
- **Migration**: Created, needs to be run in production
- **Branch**: `menu-audit`

### Pending / Follow-up

- Run migration `2026_04_07_002415_rename_platform_admin_to_tech_admin_and_remove_super_admin.php` in production
- Verify no stale `super_admin` or `platform_admin` references remain in codebase
- Update any external documentation or API docs referencing old role names
- Monitor for auth issues after migration — users previously on `super_admin` will now be `admin`

---

## [2026-04-06] Session: Temporary Password Simplification + Employee Notes API

### Intent

Replace complex auto-generated staff passwords with human-friendly ones. Build a backend API for employee notes so staff notes persist across devices and sessions (replacing frontend localStorage).

### Changes Made

#### Temporary Password Simplification

| File                                              | Change                                                                                                                                                                                                                                      | Reason                                                              |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `app/Http/Controllers/Api/EmployeeController.php` | Replaced `Str::password(12)` with new `generateSimplePassword()` private method — produces passwords like "HappyBlue42!" (adjective + noun + 2-3 digits + special char from pool of 15 adjectives × 15 nouns). Removed unused `Str` import. | Old generated passwords were too complex for staff to remember/type |

#### Employee Notes API

| File                                                                    | Change                                                                                                                                           | Reason                                                           |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------- |
| `database/migrations/2026_04_06_113513_create_employee_notes_table.php` | **NEW** — Creates `employee_notes` table: employee_id (FK), author_id (FK→users), content (text), timestamps, index on [employee_id, created_at] | Persistent storage for staff notes with author tracking          |
| `app/Models/EmployeeNote.php`                                           | **NEW** — Model with fillable=[employee_id, author_id, content], belongsTo Employee + User (author)                                              | Data model for employee notes                                    |
| `app/Models/Employee.php`                                               | Added `notes(): HasMany` relationship                                                                                                            | Employee → notes relationship for eager loading and querying     |
| `app/Http/Controllers/Api/EmployeeController.php`                       | Added `notes()` (list), `addNote()` (create), `deleteNote()` (delete with author-only authorization) methods                                     | CRUD endpoints — only the note author can delete their own notes |
| `routes/admin.php`                                                      | Added GET/POST `employees/{employee}/notes` and DELETE `employees/{employee}/notes/{note}` with appropriate permission middleware                | API routes for notes sub-resource under employees                |

### Decisions

- **Decision**: Human-friendly passwords using adjective + noun + digits + special char pattern
    - **Alternatives**: Keep `Str::password(12)`, use PIN-based temporary codes
    - **Rationale**: Staff need to type these passwords on first login; "HappyBlue42!" is memorable while still meeting complexity requirements (uppercase, lowercase, digits, special)
- **Decision**: Notes as a sub-resource of employees with author tracking
    - **Alternatives**: Generic notes system, localStorage (previous approach)
    - **Rationale**: Notes are tightly coupled to employees; author tracking enables "only author can delete" policy and audit trail
- **Decision**: Only the note author can delete their own notes
    - **Rationale**: Prevents accidental deletion of other staff members' notes while keeping the system simple (no separate moderator permission)

### Cross-Repo Impact

| File (Frontend repo)                   | Change                                                                                           | Triggered By                    |
| -------------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------- |
| `lib/api/services/employee.service.ts` | Added `EmployeeNoteResponse` type and `getNotes()`, `addNote()`, `deleteNote()` service methods  | Notes API integration           |
| `app/admin/staff/page.tsx`             | Complete redesign with StaffDetailDrawer; Notes tab now fetches from API instead of localStorage | Notes API + staff page redesign |

### Current State

- **Models**: 31 (added EmployeeNote)
- **Employee notes**: Full CRUD via API with author tracking and author-only deletion
- **Temporary passwords**: Human-friendly format (e.g., "HappyBlue42!") — 15 adjectives × 15 nouns × digits × special chars
- **Migration**: Created, needs to be run in production
- **Branch**: `menu-audit`

### Pending / Follow-up

- Run migration `2026_04_06_113513_create_employee_notes_table.php` in production
- Consider adding pagination to notes endpoint for employees with many notes
- Activity logging for note creation/deletion
- Consider adding `manage_employee_notes` permission for finer-grained access control

---

## [2026-04-06] Session: IAM Hardening — Resolve All 22 Open Audit Findings

### Intent

Close all 22 open IAM audit findings (IAM-008 through IAM-036) identified during the permission expansion session. Hardening spans suspended customer enforcement, token revocation, descriptive error responses, activity logging, PII reduction, and session management tooling.

### Changes Made

#### Critical (IAM-021, IAM-022)

| File                                                                  | Change                                                                                                                     | Reason                                                                                                            |
| --------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `app/Http/Middleware/EnsureCustomerActive.php`                        | **NEW** — Middleware that blocks suspended customers from authenticated endpoints. Returns 403 with descriptive JSON body. | IAM-021: No enforcement existed — suspended customers could still use the API                                     |
| `bootstrap/app.php`                                                   | Registered `customer.active` middleware alias                                                                              | IAM-021: Alias needed for route-level application                                                                 |
| `routes/auth.php`                                                     | Applied `customer.active` middleware                                                                                       | IAM-021: Enforce on auth-related customer routes                                                                  |
| `routes/protected.php`                                                | Applied `customer.active` middleware                                                                                       | IAM-021: Enforce on general protected routes                                                                      |
| `routes/cart.php`                                                     | Applied `customer.active` middleware                                                                                       | IAM-021: Enforce on cart operations                                                                               |
| `app/Http/Controllers/Api/Admin/CustomerController.php` — `suspend()` | Now calls `$customer->user->tokens()->delete()` and dispatches `CustomerSessionEvent`                                      | IAM-022: Suspended customers retained active tokens; now revoked immediately with real-time frontend notification |

#### High Severity (IAM-023, IAM-024)

| File                                             | Change                                                                                            | Reason                                                             |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `app/Providers/ResponseMacroServiceProvider.php` | `unauthorized()` macro now returns JSON body with `message` and `error` code instead of empty 401 | IAM-023: Empty 401 responses gave no context to API consumers      |
| `app/Http/Resources/EmployeeAuthResource.php`    | Added `'status' => $this->employee->status->value` to login response                              | IAM-024: Frontend had no way to know employee status at login time |

#### Medium Severity (IAM-026, IAM-027, IAM-028, IAM-029, IAM-032, IAM-008, IAM-019)

| File                                                                                          | Change                                                                                                   | Reason                                                                          |
| --------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/Admin/CustomerController.php` — `forceLogout()`                     | **NEW METHOD** — Dispatches event, revokes all tokens, logs activity                                     | IAM-026: No admin ability to force-logout a customer                            |
| `routes/admin.php`                                                                            | Added `POST admin/customers/{customer}/force-logout`                                                     | IAM-026: Route for new force-logout endpoint                                    |
| `app/Http/Resources/AuthUserResource.php`                                                     | Added customer `status` field to response                                                                | IAM-027: Customer status not exposed in auth user response                      |
| `app/Http/Controllers/Api/EmployeeAuthController.php` — `login()`                             | Returns descriptive error messages — wrong password vs inactive account with specific status reason      | IAM-028: Generic "invalid credentials" for all failure cases                    |
| `app/Http/Controllers/Api/EmployeeController.php` — `forceLogout()`, `requirePasswordReset()` | Now use `response()->success()` macro for consistent response format                                     | IAM-029: Inconsistent response format across employee management endpoints      |
| `app/Http/Controllers/Api/AuthController.php`                                                 | Added customer login/logout activity tracking via Spatie Activity Log                                    | IAM-032: No audit trail for customer authentication events                      |
| `app/Services/OTPService.php` — `cleanup()`                                                   | Now deletes both expired OTPs AND verified OTPs older than 1 hour                                        | IAM-008: Verified OTPs were never cleaned up, accumulating indefinitely         |
| `app/Http/Controllers/Api/OrderController.php` — `showByNumber()`                             | Returns minimal PII-free response (no customer name/phone/address, no payment details, no employee info) | IAM-019: Order tracking endpoint exposed excessive PII to unauthenticated users |

#### Low Severity (IAM-014, IAM-033, IAM-036, IAM-013)

| File                                                                   | Change                                                                                                  | Reason                                                                   |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `app/Models/User.php`                                                  | Removed `username` from `$fillable`                                                                     | IAM-014: `username` column doesn't exist in schema — dead fillable entry |
| `app/Http/Controllers/Api/EmployeeController.php` — `activeSessions()` | **NEW METHOD** — Returns list of employees with active Sanctum tokens                                   | IAM-033: No admin visibility into active staff sessions                  |
| `routes/admin.php`                                                     | Added `GET admin/employees/sessions/active`                                                             | IAM-033: Route for active sessions endpoint                              |
| `app/Http/Controllers/Api/EmployeeAuthController.php` — `login()`      | Failed login attempts logged via `activity('auth')->event('staff_login_failed')` with identifier and IP | IAM-036: No audit trail for failed staff login attempts                  |
| —                                                                      | IAM-013 (guest session tracking) accepted as low risk — deferred                                        | IAM-013: Guest sessions don't carry sensitive data; risk accepted        |

### Decisions

- **Decision**: `EnsureCustomerActive` middleware applied at route group level, not globally
    - **Rationale**: Only authenticated customer routes need the check; staff/admin routes use different auth flows
- **Decision**: Token revocation on suspend is immediate (not deferred/queued)
    - **Rationale**: Security action — suspended user should lose access within the same request, not after a queue delay
- **Decision**: `showByNumber()` returns minimal response rather than requiring authentication
    - **Rationale**: Order tracking by number is a public feature (e.g., SMS link); requiring auth would break the flow. Stripping PII is the correct mitigation.
- **Decision**: Descriptive login errors (wrong password vs inactive account) rather than generic "invalid credentials"
    - **Alternatives**: Keep generic error to prevent user enumeration
    - **Rationale**: Staff login is not public-facing (behind staff portal); UX benefit outweighs enumeration risk for internal accounts
- **Decision**: IAM-013 (guest session tracking) deferred as accepted risk
    - **Rationale**: Guest sessions don't carry PII or sensitive state; tracking adds complexity without meaningful security benefit

### Cross-Repo Impact

| File (Frontend repo)                                                                                                                       | Change                                                                                                                  | Triggered By                                                                              |
| ------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `types/staff.ts`                                                                                                                           | `StaffStatus` changed to `'active' \| 'on_leave' \| 'suspended' \| 'terminated'`; `password` removed from `StaffMember` | IAM-025, IAM-031: Status alignment with backend enum; passwords never modeled on frontend |
| `types/order.ts`                                                                                                                           | `StaffRole` re-exports from `staff.ts`                                                                                  | IAM-030: Role is an identity concern, not order concern                                   |
| `lib/api/services/employee.service.ts`                                                                                                     | Status mapping passes through backend values; removed `password: ''`; `employmentStatus` mapped from backend            | IAM-025, IAM-031: Direct backend value passthrough                                        |
| `lib/api/services/customer.service.ts`                                                                                                     | Added `forceLogoutCustomer()` method                                                                                    | IAM-026: Frontend integration for admin force-logout                                      |
| `app/admin/staff/page.tsx`                                                                                                                 | Tabs, badges, actions updated for 4-state status                                                                        | IAM-025, IAM-034, IAM-035: Terminated replaces Archived                                   |
| `app/staff/manager/staff/page.tsx`                                                                                                         | Same status updates as admin page                                                                                       | IAM-025: Parity                                                                           |
| `app/partner/staff/page.tsx`, `app/partner/dashboard/page.tsx`, `app/staff/partner/staff/page.tsx`, `app/staff/partner/dashboard/page.tsx` | All `'archived'` references changed to `'terminated'`                                                                   | IAM-025: Consistent status terminology                                                    |

### Current State

- **IAM findings**: All 22 open findings (IAM-008 through IAM-036) resolved; 1 deferred as accepted risk (IAM-013)
- **Middleware**: `EnsureCustomerActive` registered and applied to `auth.php`, `protected.php`, `cart.php`
- **Token management**: Suspend action immediately revokes tokens + broadcasts session event
- **Activity logging**: Customer login/logout, staff failed login attempts now tracked via Spatie Activity Log
- **OTP cleanup**: Handles both expired and verified-but-stale OTPs
- **PII exposure**: Order tracking endpoint stripped of customer PII, payment details, employee info
- **Admin tooling**: Force-logout for customers, active session list for employees
- **Response consistency**: All auth/employee endpoints use response macros with descriptive messages
- **Pint**: Clean
- **Branch**: `menu-audit`

### Pending / Follow-up

- Monitor `EnsureCustomerActive` middleware for false positives on edge-case auth flows
- Consider rate limiting on the staff login endpoint (failed attempts now logged but not throttled)
- `CustomerSessionEvent` listener on frontend needs to handle the force-logout/suspend broadcast gracefully
- Production deployment: verify middleware registration order in `bootstrap/app.php`

---

## [2026-04-06] Session: Permission Expansion + Legacy Employee Role Removal

### Intent

Remove the legacy `employee` role (a duplicate of `sales_staff` with identical permissions) from the backend system. Create a data migration to safely reassign existing users. Update the IAM auditor knowledge base with re-audit findings.

### Changes Made

| File                                                                             | Change                                                                                                                                                | Reason                                              |
| -------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| `app/Enums/Role.php`                                                             | Removed `case Employee = 'employee'` from Role enum (now 8 roles)                                                                                     | Legacy duplicate of sales_staff                     |
| `database/seeders/RoleSeeder.php`                                                | Removed the legacy employee role sync block                                                                                                           | Role no longer exists                               |
| `app/Http/Controllers/Api/RoleController.php`                                    | Removed `'employee' => 'Employee'` from display name map                                                                                              | Role no longer returned to frontend                 |
| `database/migrations/2026_04_06_040105_migrate_employee_role_to_sales_staff.php` | **NEW** — Reassigns users from employee→sales_staff role, cleans up role_has_permissions entries, deletes the legacy role record from the roles table | Safe migration of existing data before enum removal |
| `docs/agents/iam-auditor-kb.md`                                                  | Updated with re-audit findings IAM-021 through IAM-036, employee role removal, and permission expansion changelog entry                               | Audit documentation for 16 new findings             |

### Decisions

- **Decision**: Hard-delete the employee role record from DB via migration rather than soft-deprecate
    - **Rationale**: The employee role was an exact duplicate of sales_staff — no unique permissions or behavior. Clean removal is safe.
- **Decision**: Migration reassigns users to sales_staff before deleting the role
    - **Rationale**: Ensures no users are left without a role; sales_staff has identical permissions
- **Decision**: Migration also cleans up `role_has_permissions` entries for the deleted role
    - **Rationale**: Prevents orphaned permission records referencing a non-existent role

### Cross-Repo Impact

| File (Frontend repo)                   | Change                                                                                                   | Triggered By                        |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| `types/staff.ts`                       | Expanded `StaffPermissions` from 10 to 25 fields, added helper constants, rewrote `defaultPermissions()` | All 24 permissions now exposed      |
| `lib/api/services/employee.service.ts` | Expanded permission mapper, removed `'employee'` from BackendRole type                                   | Employee role elimination           |
| `app/admin/staff/page.tsx`             | Replaced switch with data-driven map for all 24 permissions, removed employee role filter                | Permission expansion + role removal |
| `app/staff/manager/staff/page.tsx`     | Same updates as admin page                                                                               | Parity with admin page              |

### Current State

- **Role enum**: 8 roles (tech_admin, admin, branch_manager, kitchen_staff, sales_staff, delivery_staff, cashier, pos_operator)
- **Permissions**: All 24 permissions remain in the system, now fully exposed to frontend for per-user toggling
- **Migration**: Created but needs to be run in production
- **IAM Audit KB**: 36 total findings documented (IAM-001 through IAM-036), 16 new from this session
- **Pint**: Clean
- **Branch**: `menu-audit`

### Pending / Follow-up

- Run migration `2026_04_06_040105_migrate_employee_role_to_sales_staff.php` in production
- 16 new IAM audit findings still open: 2 Critical (IAM-025 super_admin bypass, IAM-029 no role-change audit), 3 High, 7 Medium, 4 Low
- Monitor for any stale `employee` role references in route files or middleware
- Consider adding `routes/employee.php` route file cleanup (references employee-specific routes)

---

## [2026-04-06] Session: Remove Cash at Pickup Payment Method

### Intent

Remove the "Cash at Pickup" payment method from the system — it doesn't exist in the business model. Clean up the database enum, seeder, factory, and analytics service.

### Changes Made

| File                                                                                          | Change                                                                            | Reason                           |
| --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | -------------------------------- |
| `database/migrations/2026_04_06_035811_remove_cash_at_pickup_from_branch_payment_methods.php` | **NEW** — Deletes existing `cash_at_pickup` rows, alters enum to remove the value | Clean up DB schema               |
| `database/seeders/BranchSeeder.php`                                                           | Removed `cash_at_pickup` from `$paymentMethods` array                             | No longer seeded                 |
| `database/factories/BranchFactory.php`                                                        | Removed `cash_at_pickup` from `paymentMethods()->createMany()`                    | No longer created in factories   |
| `app/Services/AnalyticsService.php`                                                           | Removed `cash_at_pickup` label mapping from payment method analytics              | No longer displayed in analytics |

### Decisions

- **Decision**: Hard-delete existing rows + alter enum rather than soft-deprecate
    - **Rationale**: No orders ever used this payment method — clean removal is safe
- **Decision**: `BranchResource` doesn't need changes (dynamic mapping from DB)
    - **Rationale**: It maps whatever's in `branch_payment_methods` — with rows deleted and enum altered, it stops returning `cash_at_pickup` automatically

### Current State

- `branch_payment_methods.payment_method` enum: `momo`, `cash_on_delivery`, `card`, `bank_transfer`
- Migration applied successfully
- Pint formatting applied

### Pending / Follow-up

- **Backend validation**: Add branch-level enforcement in `CheckoutSessionController` — currently order types, payment methods, and branch active status toggles save to DB but aren't validated during order creation
- **POS fulfillment_type mapping**: `takeaway` in POS needs explicit mapping to `pickup` in branch_order_types

---

## [2026-04-06] Session: Smart Categories Admin Settings — Full CRUD + Service Integration

### Intent

Build the admin configuration backend for smart categories. The initial smart categories system was code-only — categories were computed from enum definitions with no runtime configurability. This session adds a `smart_category_settings` table and full admin CRUD so admins can enable/disable categories, adjust item limits, customize time windows, reorder display, preview resolved items, warm cache, and reset to defaults.

### Changes Made

| File                                                                             | Change                                                                                                                                                                                                                                                                                                                               | Reason                                                                                     |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| `database/migrations/2026_04_06_015552_create_smart_category_settings_table.php` | **NEW** — Creates `smart_category_settings` table with slug (unique), is_enabled, display_order, item_limit, visible_hour_start, visible_hour_end. Seeds 9 default rows from SmartCategory enum.                                                                                                                                     | Runtime configuration for each smart category                                              |
| `app/Models/SmartCategorySetting.php`                                            | **NEW** — Model with fillable, casts (bool/int), `smartCategory()` enum coercion, `hasCustomTimeWindow()` check                                                                                                                                                                                                                      | Data model for admin-configurable settings per smart category                              |
| `app/Http/Resources/SmartCategorySettingResource.php`                            | **NEW** — Returns enriched payload: id, slug, name, icon (from enum), is_enabled, display_order, item_limit, is_time_based, requires_customer, visible/default hour windows, default_item_limit                                                                                                                                      | Frontend needs both custom and default values for UI comparison                            |
| `app/Http/Requests/UpdateSmartCategorySettingRequest.php`                        | **NEW** — Validates is_enabled (bool), item_limit (1–50), visible_hour_start/end (nullable, 0–23)                                                                                                                                                                                                                                    | Form Request validation per Laravel conventions                                            |
| `app/Http/Controllers/Api/Admin/SmartCategorySettingController.php`              | **NEW** — 6 actions: index, update, reorder, preview, warmCache, resetToDefault. Preview resolves live bypassing cache. WarmCache supports branch-specific or all-branches.                                                                                                                                                          | Admin CRUD + operational tools for smart categories                                        |
| `app/Services/SmartCategories/SmartCategoryService.php`                          | **MODIFIED** — Now loads SmartCategorySetting rows (memoized via `once()`). `getActiveForContext()` checks is_enabled + custom `isVisibleAtHour()`. `resolve()` accepts optional limit, falls back to setting's item_limit. `getResolver()` changed private→public. Added `isVisibleAtHour()` and `getSettingFor()` private methods. | Service must respect admin settings for enable/disable, custom limits, custom time windows |
| `routes/admin.php`                                                               | Added 6 routes under `permission:manage_menu`: GET/PATCH smart-categories, POST reorder, GET preview, POST warm-cache, POST reset                                                                                                                                                                                                    | Admin-only access to smart category configuration                                          |
| `tests/Feature/SmartCategorySettingTest.php`                                     | **NEW** — 16 Pest tests (170 assertions): model tests, CRUD tests, reorder, reset, preview, warm-cache, service-respects-disabled                                                                                                                                                                                                    | Comprehensive test coverage for all new functionality                                      |

### Decisions

- **Decision**: Separate `smart_category_settings` table seeded from enum defaults, rather than adding columns to an existing table
    - **Rationale**: Clean separation of concerns. Migration seeds defaults so the system works immediately. Settings can diverge from enum defaults at runtime.
- **Decision**: `SmartCategoryService` uses `once()` memoization for settings loading
    - **Rationale**: Avoid repeated DB queries within a single request when multiple categories are resolved. Laravel's `once()` is per-request.
- **Decision**: Controller in `Api\Admin` namespace (not root `Api` namespace)
    - **Rationale**: Follows existing admin controller convention. All admin CRUD controllers live under this namespace.
- **Decision**: `getResolver()` changed from private to public on SmartCategoryService
    - **Rationale**: Controller's preview action needs to call resolver directly to bypass cache for live preview.
- **Decision**: Test setup deletes seeded rows in `beforeEach` to avoid unique constraint violations
    - **Rationale**: Migration seeds 9 default rows. Tests creating new rows with same slugs would violate unique constraint. Deleting first ensures clean state.
- **Decision**: Tests use `User` model (not `Employee`) for admin auth
    - **Rationale**: Spatie HasRoles trait is on User model, not Employee. Admin API authentication uses User with roles/permissions.

### Cross-Repo Impact

| File (Frontend repo)                       | Change                                                                                                                                                                 | Triggered By                               |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| `types/api.ts`                             | Added `SmartCategorySetting` (14 fields) and `SmartCategoryPreview` interfaces                                                                                         | New API response contracts                 |
| `lib/api/services/menu.service.ts`         | Added 6 methods: getSmartCategorySettings, updateSmartCategorySetting, reorderSmartCategories, previewSmartCategory, warmSmartCategoryCache, resetSmartCategorySetting | Admin API integration                      |
| `app/admin/menu/page.tsx`                  | Added "Smart Categories" tab to MENU_SUB_TABS                                                                                                                          | Navigation to new admin page               |
| `app/admin/menu-tags/page.tsx`             | Added "Smart Categories" tab to MENU_SUB_TABS                                                                                                                          | Navigation consistency                     |
| `app/admin/menu/configure/page.tsx`        | Added "Smart Categories" tab to MENU_SUB_TABS                                                                                                                          | Navigation consistency                     |
| `app/admin/menu-add-ons/page.tsx`          | Added "Smart Categories" tab to MENU_SUB_TABS                                                                                                                          | Navigation consistency                     |
| `app/admin/menu/smart-categories/page.tsx` | **NEW** — Full admin UI with category cards (toggle, limit, time windows, reorder, preview, cache warm, reset)                                                         | Admin management page for smart categories |

### Current State

- **Models**: 30 (added SmartCategorySetting)
- **Services**: SmartCategoryService now respects runtime settings (enable/disable, custom limits, custom time windows)
- **Tests**: 38 SmartCategory tests pass (253 assertions, 6.67s) — 22 original + 16 new settings tests
- **Admin routes**: 6 new endpoints under `v1/admin/smart-categories/` with `permission:manage_menu`
- **Pint**: Clean
- **Branch**: `menu-audit`

### Pending / Follow-up

- "Staff Picks" tag to replace the manual `popular` tag for curated picks
- Potential future: collaborative filtering for "You Might Like"
- Monitor smart category resolver performance at scale
- Consider adding bulk enable/disable toggle for all smart categories
- Consider drag-and-drop reorder (currently arrow-based) if UX feedback warrants it

---

## [2026-04-06] Session: Smart Categories System — Data-Driven Menu Discovery

### Intent

Replace hardcoded "CediBites Mix" and "Most Popular" categories on the customer-facing menu with a data-driven Smart Categories system. Categories like "Most Popular", "Trending", "Top Rated", "New Arrivals", time-based categories (Breakfast Favorites, Lunch Picks, Dinner Favorites, Late Night Bites), and personalized "Order Again" are now computed from actual order data, ratings, timestamps, and customer history.

### Architecture

- **Pattern**: Strategy pattern — `SmartCategory` enum maps to Resolver classes; `SmartCategoryService` orchestrates resolution + caching
- **Caching**: Laravel Cache with 6-hour TTL; warmed by `menu:compute-smart-categories` artisan command scheduled `everySixHours()`
- **Category ID Convention**: Smart categories use `smart:{slug}` prefix (e.g., `smart:most-popular`) on the frontend to distinguish from regular category names

### Changes Made

| File                                                             | Change                                                                                                                                                                                                                                                                                                                                                                                     | Reason                                                                                       |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| `app/Enums/SmartCategory.php`                                    | **NEW** — Backed PHP enum with 9 cases (MostPopular, Trending, TopRated, NewArrivals, BreakfastFavorites, LunchPicks, DinnerFavorites, LateNightBites, OrderAgain). Methods: `label()`, `icon()`, `requiresCustomer()`, `visibleHours()`, `isTimeBased()`, `isVisibleAtHour()`, `orderHours()`, `defaultLimit()`. Time windows: Breakfast 5–11, Lunch 11–15, Dinner 17–22, LateNight 21–3. | Defines all smart category types as a type-safe enum with metadata                           |
| `app/Services/SmartCategories/SmartCategoryResolver.php`         | **NEW** — Interface with `resolve(int $branchId, int $limit, ?int $customerId = null): Collection`                                                                                                                                                                                                                                                                                         | Contract for all resolver implementations                                                    |
| `app/Services/SmartCategories/Resolvers/PopularResolver.php`     | **NEW** — Queries order_items + orders (completed, paid) from last 30 days, ranks by SUM(quantity)                                                                                                                                                                                                                                                                                         | Resolves "Most Popular" items                                                                |
| `app/Services/SmartCategories/Resolvers/TrendingResolver.php`    | **NEW** — Compares order counts in last 7 days vs previous 7 days, ranks by velocity increase                                                                                                                                                                                                                                                                                              | Resolves "Trending Now" items                                                                |
| `app/Services/SmartCategories/Resolvers/TopRatedResolver.php`    | **NEW** — Filters menu_items with rating >= 4.0 AND rating_count >= 5                                                                                                                                                                                                                                                                                                                      | Resolves "Top Rated" items                                                                   |
| `app/Services/SmartCategories/Resolvers/NewArrivalsResolver.php` | **NEW** — Items with created_at within last 14 days                                                                                                                                                                                                                                                                                                                                        | Resolves "New Arrivals" items                                                                |
| `app/Services/SmartCategories/Resolvers/TimeBasedResolver.php`   | **NEW** — Accepts SmartCategory enum, uses `EXTRACT(HOUR FROM ...)` for PostgreSQL hour filtering. Handles overnight windows (e.g., 21→3).                                                                                                                                                                                                                                                 | Resolves Breakfast/Lunch/Dinner/Late Night categories                                        |
| `app/Services/SmartCategories/Resolvers/OrderAgainResolver.php`  | **NEW** — Requires customerId, queries customer's past completed orders ranked by frequency                                                                                                                                                                                                                                                                                                | Resolves personalized "Order Again" items                                                    |
| `app/Services/SmartCategories/SmartCategoryService.php`          | **NEW** — Orchestrator service. Methods: `getActiveForContext()`, `resolve()`, `warmCacheForBranch()`, `invalidateBranch()`, `hydrateItems()`. Cache key: `smart_category:{slug}:branch:{id}`, TTL: 6 hours.                                                                                                                                                                               | Central service coordinating all resolvers with caching                                      |
| `app/Console/Commands/ComputeSmartCategories.php`                | **NEW** — Artisan command `menu:compute-smart-categories {--branch=}`. Warms cache for all active branches or a specific one.                                                                                                                                                                                                                                                              | Pre-computes smart categories on schedule                                                    |
| `app/Http/Controllers/Api/SmartCategoryController.php`           | **NEW** — `index(Request)` validates `branch_id`, calls SmartCategoryService, returns JSON                                                                                                                                                                                                                                                                                                 | Public API endpoint for smart categories                                                     |
| `routes/public.php`                                              | Added `Route::get('smart-categories', ...)` and import                                                                                                                                                                                                                                                                                                                                     | Public endpoint (no auth required)                                                           |
| `routes/console.php`                                             | Added `Schedule::command('menu:compute-smart-categories')->everySixHours()`                                                                                                                                                                                                                                                                                                                | Automated cache warming                                                                      |
| `database/factories/OrderFactory.php`                            | Fixed `tax_rate`/`tax_amount` → `service_charge`                                                                                                                                                                                                                                                                                                                                           | Factory had stale column names that don't exist in DB (renamed to service_charge previously) |
| `tests/Feature/SmartCategoryTest.php`                            | **NEW** — 22 Pest tests (83 assertions): enum tests, all 6 resolver tests, service tests (active context, guest exclusion, caching, invalidation), API endpoint tests                                                                                                                                                                                                                      | Comprehensive test coverage                                                                  |

### Decisions

- **Decision**: Strategy pattern over config table — resolvers are code-defined because each has unique query logic
    - **Alternatives**: Database-driven config table for category definitions
    - **Rationale**: No migration needed — just enum + services. Each resolver has unique query logic that doesn't fit a generic config model.
- **Decision**: Cache layer instead of materialized views
    - **Alternatives**: PostgreSQL materialized views
    - **Rationale**: Laravel Cache with 6-hour TTL is simpler and sufficient; no DB schema changes required
- **Decision**: `smart:` prefix convention on frontend to distinguish smart vs regular categories
    - **Alternatives**: Separate data structures or boolean flag
    - **Rationale**: String prefix is clean, simple, and requires no structural changes to category handling
- **Decision**: PostgreSQL `EXTRACT(HOUR FROM ...)` instead of MySQL `HOUR()`
    - **Rationale**: Required for PostgreSQL compatibility. Initially used MySQL syntax which failed in tests.
- **Decision**: Route prefix `v1` not `api`
    - **Rationale**: App uses `apiPrefix: 'v1'` in bootstrap/app.php. Tests initially used `/api/` prefix which caused 404s.
- **Decision**: No migration needed — smart categories are computed at runtime and cached
    - **Rationale**: Avoids schema changes; data is derived from existing order/menu tables
- **Decision**: Fix OrderFactory `tax_rate`/`tax_amount` → `service_charge`
    - **Rationale**: Factory had stale column names from a previous schema rename; discovered during test debugging

### Cross-Repo Impact

| File (Frontend repo)                                 | Change                                                                        | Triggered By                               |
| ---------------------------------------------------- | ----------------------------------------------------------------------------- | ------------------------------------------ |
| `types/api.ts`                                       | Added `SmartCategory` interface: `{ slug, name, icon, item_ids }`             | New smart categories API response contract |
| `lib/api/services/menu.service.ts`                   | Added `getSmartCategories(branchId)`                                          | API integration for smart categories       |
| `lib/api/hooks/useSmartCategories.ts`                | **NEW** — TanStack Query hook with 10-minute staleTime                        | React Query hook for data fetching         |
| `app/components/providers/MenuDiscoveryProvider.tsx` | Integrated smart categories, replaced hardcoded "Most Popular" logic          | Dynamic smart category integration         |
| `app/components/ui/MenuGrid.tsx`                     | Replaced hardcoded "Most Popular" section with generic smart category section | Dynamic rendering of active smart category |
| `app/(customer)/menu/page.tsx`                       | Categories now objects `{id, label}` with smart category support              | Mixed category types (smart + regular)     |

### Current State

- **Tests**: 22 Pest tests pass (83 assertions, 6.79s)
- **Formatting**: Pint passes clean
- **API endpoint**: `GET /v1/smart-categories?branch_id={id}` — public, no auth required
- **Scheduled task**: `menu:compute-smart-categories` runs every 6 hours
- **Smart categories**: MostPopular, Trending, TopRated, NewArrivals, BreakfastFavorites, LunchPicks, DinnerFavorites, LateNightBites, OrderAgain
- **Branch**: `menu-audit`

### Pending / Follow-up

- Admin settings UI for toggling/configuring smart categories (enable/disable individual categories, adjust limits)
- "Staff Picks" tag to replace the manual `popular` tag for curated picks
- Potential future: collaborative filtering for "You Might Like"
- Consider adding smart category configuration to the admin menu management page
- Monitor smart category resolver performance at scale — may need query optimization or index additions

---

## [2026-04-05] Session: Menu Item Image Display Fix — Route-Based Media Serving

### Intent

Fix menu item images not displaying on customer-facing menu pages. API returned image URLs like `https://beta-api.cedibites.com/storage/17/...` but Nginx returned 403 Forbidden for `/storage/` paths. Images were stored correctly in Spatie Media Library but inaccessible via the web. Solution: serve media through a Laravel controller that bypasses Nginx static file restrictions, with proper caching headers and thumbnail support.

### Changes Made

| File                                            | Change                                                                                                                                                                                                                                                            | Reason                                                                                                |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/MediaController.php`  | **NEW** — Controller that serves media files through Laravel. Includes ETag/Last-Modified headers, 304 Not Modified conditional caching, `Cache-Control: public, max-age=31536000, immutable`. Supports optional `/{conversion}` parameter for thumbnail serving. | Bypasses Nginx 403 on `/storage/` paths; gives application-level control over caching and conversions |
| `routes/public.php`                             | Added `GET /v1/media/{media}/{conversion?}` public route named `media.show`                                                                                                                                                                                       | Public endpoint for all media access — no auth required for menu images                               |
| `app/Http/Resources/MenuItemResource.php`       | `image_url` now uses `route('media.show', $media)` instead of `getFirstMediaUrl()`. Added `thumbnail_url` field using `route('media.show', [$media, 'thumbnail'])`                                                                                                | Route-based URLs are always accessible; thumbnails enable faster grid loading                         |
| `app/Http/Resources/MenuItemOptionResource.php` | Same changes as MenuItemResource: route-based `image_url` and new `thumbnail_url`                                                                                                                                                                                 | Options also have images; same fix needed                                                             |
| `app/Models/MenuItemOption.php`                 | Added Spatie `registerMediaConversions()` with `thumbnail` conversion (400×300px, sharpened, `nonQueued()`)                                                                                                                                                       | Thumbnails generated synchronously on upload for option images                                        |

### Decisions

- **Decision**: Serve media through Laravel (MediaController) rather than fixing Nginx config
    - **Alternatives**: Fix Nginx config to allow `/storage/` access; use a CDN
    - **Rationale**: (a) Can't guarantee server Nginx config access in all environments, (b) application-level caching headers (ETag, 304), (c) supports conversion parameter for thumbnails, (d) works identically in dev and production
- **Decision**: `Cache-Control: public, max-age=31536000, immutable` on media responses
    - **Rationale**: Spatie uses unique media IDs — once an image is uploaded, its URL never changes. The image at `/v1/media/17` is permanent. Immutable caching is safe and maximizes CDN/browser cache hits.
- **Decision**: Thumbnail conversion is `nonQueued()` — generated synchronously on upload
    - **Alternatives**: Queued conversion via Laravel jobs
    - **Rationale**: Menu images are uploaded infrequently (admin action, not user-facing); sync generation is simpler and avoids "thumbnail not ready" race conditions
- **Decision**: 400×300px thumbnail size with sharpening
    - **Rationale**: Matches typical card grid display size; sharpening compensates for downscale blur

### Cross-Repo Impact

| File (Frontend repo)                                                                        | Change                                                              | Triggered By                                                            |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `next.config.ts`                                                                            | Removed `unoptimized: true`, added `remotePatterns` for API domains | Image URLs now point to API host — Next.js needs to proxy/optimize them |
| `types/api.ts`                                                                              | Added `thumbnail_url` to `MenuItem` and `MenuItemOption` interfaces | New `thumbnail_url` field in API resources                              |
| `app/components/providers/MenuDiscoveryProvider.tsx`                                        | Maps `thumbnail_url` → `thumbnail` in search items                  | Consumer of new thumbnail data                                          |
| `app/components/ui/MenuItemCard.tsx`                                                        | Prefers `thumbnail` over `image` for grid display                   | Performance optimization using new thumbnails                           |
| `lib/utils/compressImage.ts`, `lib/api/services/menu.service.ts`, `app/admin/menu/page.tsx` | Removed debug `console.log` statements                              | Cleanup from previous image upload session                              |

### Current State

- **Media serving**: All menu images served via `GET /v1/media/{media}/{conversion?}` — no more Nginx 403
- **Caching**: ETag + Last-Modified + immutable Cache-Control on all media responses; 304 Not Modified for conditional requests
- **Thumbnails**: `MenuItemOption` model has `thumbnail` conversion (400×300, sharpened, sync). `MenuItem` thumbnails depend on whether the model already had `registerMediaConversions()`.
- **API Resources**: Both `MenuItemResource` and `MenuItemOptionResource` return `image_url` (route-based) and `thumbnail_url`
- **Route**: `media.show` registered in `routes/public.php`, no auth required
- **Branch**: `payment-order-bug-fixes`

### Pending / Follow-up

- Run `php artisan media-library:regenerate --only-missing` on production to backfill thumbnails for existing images
- Verify `MenuItem` model also has `registerMediaConversions()` with thumbnail — if not, add it for consistency
- Consider adding rate limiting to the media endpoint if abuse is a concern
- Monitor media serving performance — if high traffic, consider adding a CDN layer in front

---

## [2026-04-04] Session: syncOptions Race Condition Fix — Backend Confirmation (No Changes)

### Intent

Investigate the root cause of "images not showing on customer side" and the "Item saved but image/option sync failed" error reported on the frontend menu management pages. Determine whether the backend's `MenuItemOptionController::destroy()` 422 guard needed modification.

### Changes Made

| File                       | Change | Reason                                 |
| -------------------------- | ------ | -------------------------------------- |
| _No backend files changed_ | —      | The fix was applied frontend-side only |

### Investigation Findings

The backend's `MenuItemOptionController::destroy()` method returns HTTP 422 when attempting to delete the last remaining option on a menu item (`count() <= 1` guard). This is **correct behavior** — menu items must always have at least one option (the 'standard' option for simple-priced items, or named options for multi-option items).

The bug was in the frontend's `syncOptions()` function ordering: it deleted non-desired options BEFORE creating new ones. When a user replaced ALL option keys with entirely new ones (zero overlap between old and new), the delete loop tried to remove every existing option, hitting the last-option guard. This caused the entire `syncOptions()` promise chain to fail, which also prevented image uploads (the image upload step runs at the end of the chain).

### Decisions

- **Decision**: Backend's `count() <= 1` guard in `destroy()` is correct and should NOT be changed
    - **Rationale**: Menu items must always have at least one option; the guard prevents data integrity issues
- **Decision**: No bulk-sync endpoint needed at this time
    - **Alternatives**: Could create a `PUT /menu-items/{id}/options` bulk-sync endpoint
    - **Rationale**: Frontend reorder (upsert-then-delete) is the minimal fix; a bulk endpoint adds complexity without clear benefit right now

### Cross-Repo Impact

| File (Frontend repo)              | Change                                                                          | Impact                                                                                    |
| --------------------------------- | ------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `app/admin/menu/page.tsx`         | Reordered `syncOptions()`: upsert desired options first, then delete stale ones | Fixes the 422 error when replacing all option keys; fixes downstream image upload failure |
| `app/staff/manager/menu/page.tsx` | Same reorder applied to BM menu page                                            | Same fix applied for consistency                                                          |

### Current State

- `MenuItemOptionController::destroy()` — unchanged, 422 guard for last-option deletion remains
- `MenuItemOptionController::store()` and `update()` — unchanged, work correctly for upsert operations
- The frontend now calls these endpoints in the correct order (create/update first, delete second)
- Branch: `payment-order-bug-fixes`

### Note on Category Slug Fix

The category slug fix from the Menu Management Audit session (adding `slug` to `CreateMenuCategoryRequest` and `UpdateMenuCategoryRequest` validation rules, and auto-generating slug in `MenuCategoryController::store()`) is recorded in the "Menu Management Audit — Full-Stack Fixes" entry above.

### Pending / Follow-up

- Consider a bulk-sync endpoint (`PUT /menu-items/{id}/options`) if frontend option management grows more complex
- `MenuItemOptionController::destroy()` could benefit from a more descriptive error message (e.g., "Cannot delete the last option on a menu item")

---

## [2026-04-04] Session: Menu Management Audit — Full-Stack Fixes

### Intent

Comprehensive audit and fix of the entire menu system across admin and branch manager portals. Fix critical bugs in category CRUD validation, API resource serialization, and broken query logic in multi-branch scenarios.

### Changes Made

| File                                                  | Change                                                                                                                                                                                       | Reason                                                                                                              |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `app/Http/Requests/CreateMenuCategoryRequest.php`     | Added `branch_id` required validation; changed name uniqueness from global `unique:menu_categories,name` to branch-scoped `Rule::unique('menu_categories', 'name')->where('branch_id', ...)` | Category names were globally unique but should be unique per-branch (migration has `unique(['branch_id', 'slug'])`) |
| `app/Http/Requests/UpdateMenuCategoryRequest.php`     | Added `branch_id` to rules; scoped name uniqueness to branch with `Rule::unique(...)->where('branch_id', $branchId)->ignore($category)`                                                      | Same branch-scoping fix for updates                                                                                 |
| `app/Http/Resources/MenuCategoryResource.php`         | Added `'branch_id' => $this->branch_id` to output array                                                                                                                                      | Frontend type contract expects branch_id; was missing from serialized response                                      |
| `app/Http/Controllers/Api/MenuCategoryController.php` | Removed `groupBy('name')->map(fn($group) => $group->first())->values()` from `index()`                                                                                                       | Deduplication collapsed categories with same name across branches into one, breaking multi-branch scenarios         |

### Decisions

- **Decision**: Category name uniqueness scoped to branch_id
    - **Rationale**: Migration already defines `unique(['branch_id', 'slug'])` compound index; name uniqueness should follow the same branch scope
- **Decision**: Removed deduplication from category index
    - **Rationale**: Frontend handles filtering by branch_id via query params; server-side dedup broke multi-branch displays

### Cross-Repo Impact

| File (Frontend repo)                                          | Change                                                           | Triggered By                                                                               |
| ------------------------------------------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `types/api.ts`                                                | Added `branch_id?: number` and `slug?: string` to `MenuCategory` | `MenuCategoryResource` now serializes `branch_id`                                          |
| `app/staff/manager/menu/page.tsx`                             | Fixed `useMenuCategories` to pass `branch_id`                    | Categories must be branch-scoped to prevent cross-branch assignment                        |
| `app/admin/menu/page.tsx`                                     | Fixed `toggleGlobal()` to persist via API                        | Availability toggle was frontend-only; needed API round-trip                               |
| `app/admin/menu/page.tsx` + `app/staff/manager/menu/page.tsx` | Added `uploadSimpleImage()`                                      | Backend creates 'standard' option for simple items; frontend wasn't uploading images to it |

### Current State

- Menu categories: CRUD properly branch-scoped, returns branch_id, no dedup
- All 34 menu routes compile
- All modified PHP files pass syntax check
- Branch: `payment-order-bug-fixes`

### Pending / Follow-up

- Consider `MenuManagementService` to centralize scattered controller logic
- Add indexes on frequently queried menu columns for performance at scale
- Menu validation artisan command (detect orphans, missing prices, slug collisions)

---

## [2026-04-04] Session: Settings Toggle Persistence, Delivery Fee Toggle & Global Operating Hours

### Intent

Fix a bug where admin settings toggles reset on page refresh despite saving correctly. Add a `delivery_fee_enabled` toggle to control whether delivery fees appear in checkout. Add global operating hours settings that admins can edit and that display dynamically in the customer-facing footer.

### Changes Made

| File                                                                                      | Change                                                                                                                                                                             | Reason                                                                                                                                                                                                    |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/Admin/SystemSettingController.php`                              | Changed `index()` to return raw `$row->value` strings from DB instead of using `$this->settings->get()` which casts booleans                                                       | **Bug fix**: Frontend compares `s.value === 'true'` (string), but PHP cast returned JSON booleans (`true`/`false`), causing toggles (service charge, manual entry) to always reset to defaults on refresh |
| `database/migrations/2026_04_04_072214_add_delivery_fee_and_operating_hours_settings.php` | **NEW** — Seeds `delivery_fee_enabled` (boolean, default false), `global_operating_hours_open` (string, default "07:00"), `global_operating_hours_close` (string, default "22:00") | New system settings for delivery fee visibility and globally editable operating hours                                                                                                                     |
| `routes/public.php`                                                                       | Expanded `/checkout-config` endpoint to return `delivery_fee_enabled`, `global_operating_hours_open`, `global_operating_hours_close`                                               | Frontend checkout and footer need these values from a public (unauthenticated) endpoint                                                                                                                   |
| `routes/employee.php`                                                                     | Extended settings allowlist to include `delivery_fee_enabled`, `global_operating_hours_open`, `global_operating_hours_close`                                                       | Employee-facing settings endpoints need read access to these values                                                                                                                                       |

### Decisions

- **Decision**: Return raw string values from `SystemSettingController::index()` instead of cast values
    - **Alternatives**: Change frontend to compare booleans instead of strings
    - **Rationale**: The frontend already has string-based comparison (`s.value === 'true'`) established across all settings consumers; changing the API to return strings is less invasive and consistent with how settings are stored
- **Decision**: `delivery_fee_enabled` defaults to `false`
    - **Rationale**: Delivery fees were already "temporarily disabled" in the codebase; defaulting to off maintains current behavior
- **Decision**: Operating hours are global (not per-branch) for now
    - **Alternatives**: Per-branch operating hours (already modeled in `BranchOperatingHour`)
    - **Rationale**: Simpler starting point; can be evolved to per-branch later if needed
- **Decision**: Service charge math (1% of ₵0.10 = ₵0.001 → rounds to ₵0.00) is correct
    - **Rationale**: Mathematically correct rounding behavior, not a bug

### Cross-Repo Impact

| File (Frontend repo)               | Change                                                                                                     | Triggered By                                                                                       |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `app/admin/settings/page.tsx`      | Added "Enable Delivery Fee" toggle in Order Settings tab; General tab operating hours now connected to API | New `delivery_fee_enabled`, `global_operating_hours_open`, `global_operating_hours_close` settings |
| `app/(customer)/checkout/page.tsx` | Delivery fee row conditionally rendered via `deliveryFeeEnabled` prop on `OrderSummary`                    | `delivery_fee_enabled` setting from `/checkout-config`                                             |
| `app/components/layout/Footer.tsx` | Fetches hours from `/checkout-config` instead of hardcoded values; uses `formatTime12h()` helper           | `global_operating_hours_open/close` now available from API                                         |

### Current State

- **Settings toggle persistence**: Fixed — API returns raw strings, frontend string comparisons work correctly
- **Delivery fee**: Controlled by `delivery_fee_enabled` toggle (default off); checkout hides delivery fee row when disabled
- **Operating hours**: Editable from Admin Settings > General tab; displayed dynamically in customer Footer
- **`/checkout-config` endpoint**: Now returns `service_charge_enabled`, `service_charge_percent`, `service_charge_cap`, `delivery_fee_enabled`, `global_operating_hours_open`, `global_operating_hours_close`
- **System settings table**: Now has 6 configurable settings total
- **Branch**: `payment-order-bug-fixes`

### Pending / Follow-up

- Per-branch operating hours could be added if different branches need different schedules
- Consider adding validation for operating hours format (HH:MM) in the API
- Delivery fee amount/calculation logic to be implemented when delivery fees are enabled

---

## [2026-04-04] Session: Order Audit & Security Bug Fixes

### Intent

A comprehensive audit of the order/payment/checkout pipeline was performed across both repos, identifying 7 bugs — including security vulnerabilities (MoMo payment bypass, cross-branch authorization gaps), invalid status transitions, and hardcoded service charge logic. All were fixed in this session on the `payment-order-bug-fixes` branch.

### Changes Made

| File                                                                    | Change                                                                                                                                                        | Reason                                                                                                                                            |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Http/Requests/UpdateOrderStatusRequest.php`                        | Removed `pending`, `confirmed`, `cancelled` from allowed status values in validation rules                                                                    | `pending`/`confirmed` are system-set during checkout flow; `cancelled` requires admin cancel approval flow — none should be manually set by staff |
| `app/Http/Controllers/Api/CheckoutSessionController.php`                | Added `->where('payment_method', 'cash')` filter to `confirmCash()` query                                                                                     | **Security fix**: Without this, staff could call confirmCash on a MoMo session, bypassing Hubtel payment gateway verification                     |
| `app/Http/Controllers/Api/CheckoutSessionController.php`                | Added `$this->verifyStaffAuthorization($employee, $session->branch_id)` to `confirmCash()` and `confirmCard()`                                                | **Security fix**: Without this, staff from Branch A could confirm payments for Branch B sessions                                                  |
| `app/Http/Controllers/Api/CheckoutSessionController.php`                | Updated service charge calculation in `store()` to use `service_charge_enabled`, `service_charge_percent`, and `service_charge_cap` from SystemSettingService | Replaced hardcoded 1% with configurable percent + cap; charge is 0 when disabled                                                                  |
| `database/migrations/2026_04_04_060840_add_service_charge_settings.php` | **NEW** — Seeds `service_charge_enabled` (boolean, default true) and `service_charge_cap` (integer, default 5) system settings                                | Complement existing `service_charge_percent` setting; cap ensures charge never exceeds X GHS                                                      |
| `routes/public.php`                                                     | Added `GET /checkout-config` public endpoint returning `service_charge_enabled`, `service_charge_percent`, `service_charge_cap`                               | Frontend needs service charge info before user is authenticated at checkout                                                                       |
| `routes/employee.php`                                                   | Extended settings allowlist to include `service_charge_enabled` and `service_charge_cap`                                                                      | Employee-facing settings endpoints need read access to these values                                                                               |

### Decisions

- **Decision**: Don't touch Hubtel callback `orWhere` scoping (searches by both CheckoutNumber and InvoiceNumber)
    - **Alternatives**: Tighten to `where` + `where` with AND logic
    - **Rationale**: Statistically negligible collision risk, and changing it could break working payment callbacks in production
- **Decision**: Service charge cap defaults to 5 GHS with admin toggle
    - **Rationale**: User requirement — 1% capped at 5 GHS, configurable from admin settings
- **Decision**: Public `/checkout-config` endpoint (no auth required)
    - **Alternatives**: Authenticated endpoint, embed in checkout session response
    - **Rationale**: Frontend needs service charge info before user is authenticated at checkout
- **Decision**: Branch authorization added to `confirmCash()`/`confirmCard()` but not to other checkout session methods
    - **Rationale**: Other methods already have appropriate auth or are called via different flows
- **Decision**: Pint formatting applied to changed PHP files only (`--dirty` flag)
    - **Rationale**: Keep formatting changes scoped to files touched in this session

### Cross-Repo Impact

| File (Frontend repo)               | Change                                                                                                                 | Triggered By                                                          |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `app/(customer)/checkout/page.tsx` | Added `ServiceChargeConfig` interface, `calcServiceCharge()` helper, `scConfig` state fetching from `/checkout-config` | New public endpoint + dynamic service charge config                   |
| `app/(customer)/checkout/page.tsx` | Updated `OrderSummary` and `StepPayment` to use dynamic service charge with cap                                        | Backend service charge calculation now uses percent + cap             |
| `app/admin/settings/page.tsx`      | Added service charge toggle, percentage input, cap input with conditional rendering                                    | New `service_charge_enabled` and `service_charge_cap` system settings |

### Current State

- **Security**: `confirmCash()` restricted to cash-only sessions; both confirm methods enforce branch authorization
- **Status transitions**: `UpdateOrderStatusRequest` only allows valid staff-settable statuses
- **Service charge**: Fully configurable via SystemSettingService — enabled/disabled toggle, percentage, cap (GHS)
- **System settings**: `service_charge_enabled`, `service_charge_percent`, `service_charge_cap` all in `system_settings` table
- **Pint**: Passes clean
- **Branch**: `payment-order-bug-fixes`

### Bugs Fixed Summary

1. `UpdateOrderStatusRequest` allowed invalid statuses (`pending`, `confirmed`, `cancelled`)
2. `confirmCash()` could be called on non-cash payment sessions (MoMo bypass)
3. `confirmCash()` + `confirmCard()` had no branch authorization check
4. Service charge was hardcoded to 1% with no cap, no admin toggle
5. Frontend used hardcoded service charge calculation, didn't fetch config from API
6. Admin settings UI had no service charge toggle or cap controls
7. Pint formatting applied to changed PHP files

### Pending / Follow-up

- POS checkout flow may also need service charge config integration
- Consider adding rate limiting to the public `/checkout-config` endpoint
- Hubtel callback `orWhere` scoping left as-is — revisit if collision issues arise

---

## [2026-04-04] Session: Project Chronicle Agent Setup

### Intent

Create an institutional memory system for the CediBites project — a "Project Chronicle" agent that silently observes all changes across sessions, records them, and can brief developers and other agents on the current state of any part of the system.

### Changes Made

| File                                                      | Change                                        | Reason                                                                                                     |
| --------------------------------------------------------- | --------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `.github/agents/project-chronicle.agent.md`               | Created agent definition                      | New agent with read/search/edit/agent/todo tools, mandatory cross-referencing, structured chronicle format |
| `.github/instructions/chronicle-reminder.instructions.md` | Created instruction file with `applyTo: "**"` | Auto-reminds all agents to ask user about updating the chronicle after every code change                   |
| `PROJECT_CHRONICLE.md`                                    | Created knowledge base file                   | Seeded with system map (routes, models, services, architecture, integrations)                              |

### Cross-Repo Impact

| File (Frontend repo)                                      | Change                         | Reason                                                                        |
| --------------------------------------------------------- | ------------------------------ | ----------------------------------------------------------------------------- |
| `.github/agents/project-chronicle.agent.md`               | Mirror of API agent definition | Same agent available in both repos                                            |
| `.github/instructions/chronicle-reminder.instructions.md` | Mirror of API instruction      | Reminder works regardless of which repo is being edited                       |
| `PROJECT_CHRONICLE.md`                                    | Frontend knowledge base file   | Seeded with system map (route groups, state management, API layer, utilities) |

### Decisions

- **Decision**: Hybrid approach — seed with system map, build change log incrementally
    - **Alternatives**: Full scan first vs. purely incremental
    - **Rationale**: Gives the agent useful context from day 1 without requiring a massive upfront audit
- **Decision**: Place agent + chronicle in both repos
    - **Rationale**: Agent needs to be discoverable from either workspace; chronicle files track repo-specific changes
- **Decision**: Mandatory cross-referencing between repos
    - **Rationale**: API and frontend are tightly coupled — changes in one often affect the other
- **Decision**: `applyTo: "**"` for the reminder instruction
    - **Rationale**: Should trigger after editing any file type, not just specific ones

### Current State

The Project Chronicle system is fully set up and operational:

- Agent definition in both repos (`.github/agents/project-chronicle.agent.md`)
- Auto-reminder instruction in both repos (`.github/instructions/chronicle-reminder.instructions.md`)
- Knowledge base files in both repo roots (`PROJECT_CHRONICLE.md`)
- System maps seeded; change log ready for entries

### Pending / Follow-up

- First real development session will test the reminder flow end-to-end
- Chronicle entries will accumulate as sessions happen
