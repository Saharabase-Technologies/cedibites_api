---
description: "Use when: writing or reviewing any code, creating new files, refactoring, modularizing logic, splitting large files, building services or engines, designing components or hooks. Always-on code-quality and modularity rules for the entire CediBites platform."
applyTo: "**"
---

# Code Quality & Modularity — Always-On Rules

These rules tighten the existing engineering practices (see `Engineering-practices.instructions.md`) and apply to **every file** written across both repos. The goal: every piece of code does **one thing**, lives in the **smallest sensible unit**, and is **trivially testable in isolation**.

When work touches the IMS module, these rules are stricter (see thresholds below).

---

## 1. The One-Function Rule

Every function, method, class, component, hook, and service has **ONE job**. Name it with a verb that describes that one job. If you have to use "and" to describe what it does, it's two functions.

**Bad:**

```php
public function processOrderAndDeductInventoryAndNotifyKitchen(Order $order) { ... }
```

**Good:** three separate, composable units.

---

## 2. File Size Caps

| Type                 | Soft cap (refactor) | Hard cap (must split) |
| -------------------- | ------------------- | --------------------- |
| PHP Controller       | 120 lines           | 200 lines             |
| PHP Service / Engine | 200 lines           | 300 lines             |
| PHP Model            | 120 lines           | 250 lines             |
| PHP FormRequest      | 80 lines            | 150 lines             |
| PHP API Resource     | 80 lines            | 150 lines             |
| React Page Component | 120 lines           | 200 lines             |
| React UI Component   | 100 lines           | 180 lines             |
| React Hook           | 60 lines            | 120 lines             |
| TypeScript Type File | 150 lines           | 300 lines             |

When a file approaches the soft cap, **split it before adding more code**. Splitting strategies:

- **Service god-class** → split into `*Reader`, `*Writer`, `*Calculator`, `*Validator` siblings.
- **Controller too big** → extract Service(s); each public action stays a thin orchestrator.
- **Component too big** → extract sub-components into a sibling `_components/` folder; extract logic into hooks.
- **Hook too big** → split by concern (data hook + mutation hook + derived-state hook).

---

## 3. Engine Pattern (Required for Complex Logic)

Any non-trivial multi-step business logic MUST be implemented as an **Engine class**:

- Single public method, fully unit-testable.
- Constructor takes its dependencies (no service-locator, no facades inside).
- No HTTP, no Auth, no Request, no Response — pure domain.
- Lives under the relevant Domain folder, e.g. `app/Domain/Inventory/Wastage/Engines/WastageApprovalEngine.php`.

Required IMS engines (initial set; more allowed):

- `MovementPostingEngine` · `TransferStockValidator` · `WastageApprovalEngine` · `RecipeVersioningEngine` · `DailyClosingVarianceCalculator` · `CycleReconciliationEngine` · `DisputeResolutionService` · `StockLedgerReportService` · `WeightedAverageCostCalculator` · `ReorderSuggestionEngine` · `IngredientDeductionForOrderJob`

Each engine has a matching Pest unit test file.

---

## 4. Module / Domain Structure

### Backend (`cedibites_api`)

- New domains live under `app/Domain/{Domain}/` — sibling to existing `app/Services/`.
- Each domain sub-folder contains: `Models/` (or shares root `app/Models/`), `Services/`, `Engines/`, `Events/`, `Listeners/`, `Jobs/`, `Policies/`, `Http/Requests/`, `Http/Resources/`, `Http/Controllers/`.
- Each domain registers its own ServiceProvider when bindings are needed.
- IMS specifically: `app/Domain/Inventory/{Movements,Transfers,Recipes,Wastage,Counts,Reconciliation,Reports,Purchases}/`.

### Frontend (`cedibites`)

- Feature folders mirror backend domains: `lib/api/services/inventory/`, `lib/api/hooks/inventory/`, `types/inventory.ts`.
- UI code lives in route-group folders: `app/inventory/{movements,transfers,recipes,...}/_components/`.
- Shared atomic primitives go to `app/inventory/_components/` (e.g. `StockBadge`, `MovementRow`, `LocationSelector`).
- Pages stay thin orchestrators — they compose components and call hooks; they hold no business logic.

---

## 5. Anti-Patterns (Forbidden)

- ❌ "Utility dumping ground" files (`helpers.php`, `utils.ts` accumulating unrelated functions). Group by purpose; create properly named modules.
- ❌ God Services (`OrderService` doing payments, inventory, notifications, analytics). Split by sub-domain.
- ❌ Logic in controllers beyond: validate → call service → return resource.
- ❌ Logic in React components beyond: consume hooks → render. No `fetch`, no business rules.
- ❌ `any`, `unknown` without a type guard, or `// @ts-ignore` without a written justification.
- ❌ Duplicated functions — search the codebase before creating a new helper.
- ❌ Boolean parameter flags that change behavior (`doThing($x, true)`). Split into two methods.
- ❌ Comments explaining what the code does (it should be self-evident); comments explaining **why** are encouraged when non-obvious.

---

## 6. Pre-Commit Checklist (Per File Touched)

Before considering a file done:

- [ ] File is under its hard cap.
- [ ] Every function does one thing; name reflects that.
- [ ] No business logic in controllers / components.
- [ ] Public methods have explicit return types (PHP) and explicit return types (TS).
- [ ] No `env()` outside config files (PHP).
- [ ] No raw enum string comparisons — use Enum constants.
- [ ] No `DB::` calls when `Model::query()` works.
- [ ] All multi-write operations wrapped in `DB::transaction()`.
- [ ] All eager-loadable relationships eager-loaded (no N+1).
- [ ] PHP: `vendor/bin/pint --dirty --format agent` passes.
- [ ] TS: `npm run lint` and `tsc --noEmit` pass on touched files.
- [ ] At least one test exists for new logic.

---

## 7. When You Disagree With These Rules

Don't silently bypass. Surface the case to the developer with: the rule, why you want to deviate, the alternative, and the trade-off. Wait for explicit approval.
