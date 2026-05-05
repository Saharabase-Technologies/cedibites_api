# IMS Session Handoff — May 5, 2026

> **For the next chat session.** Read this first when resuming IMS work.

## Goal / Intent

Plan and lock the architecture for the **CediBites Inventory Management System (IMS)** before any code is written. Strategic decision phase — Master Orchestrator coordinating, no agents delegated yet.

## Progress Summary

- [x] v1 architecture proposed
- [x] All 8 initial decisions answered
- [x] Both transcript files (CBIMS + CBIMS 2) read
- [x] v2 architecture produced with 17 transcript-derived nuances incorporated
- [x] All 11 locked architectural decisions confirmed
- [x] All 4 final open decisions answered (G1–G4):
    - G1: Inter-branch transfer approval → **Branch B's manager approves**
    - G2: Daily closing entry → **mandatory**
    - G3: Per-branch recipe override → **full replace, no inheritance**
    - G4: POS / phone / order serial / SMS / finance → **explicitly out of scope**
- [x] Foundation files created (this session):
    - [`cedibites_api/.github/instructions/ims-considerations.instructions.md`](../../.github/instructions/ims-considerations.instructions.md)
    - [`cedibites/.github/instructions/ims-considerations.instructions.md`](../../../../cedibites/.github/instructions/ims-considerations.instructions.md)
    - [`cedibites_api/.github/instructions/code-quality.instructions.md`](../../.github/instructions/code-quality.instructions.md)
    - [`cedibites/.github/instructions/code-quality.instructions.md`](../../../../cedibites/.github/instructions/code-quality.instructions.md)
    - [`cedibites_api/.github/agents/inventory-auditor.agent.md`](../../.github/agents/inventory-auditor.agent.md)
    - [`cedibites/.github/agents/inventory-auditor.agent.md`](../../../../cedibites/.github/agents/inventory-auditor.agent.md)
    - [`cedibites_api/docs/agents/inventory-auditor-kb.md`](../agents/inventory-auditor-kb.md)
    - [`cedibites_api/docs/inventory/architecture.md`](architecture.md) ← **read this for the full architecture**
- [ ] DevOps / pipeline audit (beta↔main auto-merge concern) — blocked, awaiting user
- [ ] Phase 0 kickoff — not started, awaiting user signal
- [ ] PROJECT_CHRONICLE entries for both repos — not yet written this session

## Key Decisions Made (All Locked)

See [`ims-considerations.instructions.md` §1–§2](../../.github/instructions/ims-considerations.instructions.md) for the full canonical list. Highlights:

1. **Same domain, dedicated portal** at `cedibites/app/inventory/` (POS/KDS pattern)
2. **Separate `inventory_locations` table** (warehouse + satellites), branches table untouched
3. **Single warehouse MVP**, schema supports N
4. **Append-only ledger + denormalized balances** with idempotency keys
5. **Deduction on `OrderCompleted`** event, queued, idempotent
6. **Recipes**: global default + per-branch override (Admin only, **full replace**, no inheritance), versioned, `draft → observation → locked` status machine
7. **Weighted-average costing** for MVP
8. **Batch/FEFO schema present day-one**, UI Phase 3
9. **Tablet-first** UX, operator vocabulary ("mother kitchen", "satellite", "requisition", etc.)
10. **Stack**: Laravel 12 + MySQL + Sanctum + Reverb + Next.js 16 — **no Supabase, no new deps**
11. **Wastage threshold ₵500** default, configurable per location
12. **Inter-branch transfer**: Branch B's manager approves; warehouse manager notified read-only
13. **Daily closing entry mandatory**; missed days flagged on variance report
14. **Disputed transfers immutable**; resolution = NEW corrective transfer linked via `parent_transfer_id`
15. **Source-stock validation** on every transfer creation (admin override permission required)
16. **Reconciliation cycles**: manager-initiated, NOT calendar-enforced
17. **Stock Ledger canonical columns**: Opening · Received · Transfers In · Transfers Out · Sales (BOM) · Wastage · Expected Closing · Actual Closing · Variance
18. **Out of scope**: POS phone, order serial refactor, service-charge separation, SMS routing, finance module

## Thinking Patterns & Approach

- **Strict module isolation** — `inventory_*` tables only, FKs point OUT only, no ALTERs to existing tables, dedicated queue, one-way event coupling
- **Append-only ledger as source of truth** — denormalized balances for hot reads; reversals are NEW rows, never edits
- **Engine pattern for all complex logic** — single-purpose classes, fully unit-testable, no facades inside
- **Operator-language UX** — "Branch Manager Test": non-technical user understands in 30 seconds
- **Phased rollout behind feature flag** — frequent small flag-OFF PRs to main, staging on prod-snapshot DB, prod flips after UAT

## Challenges & Solutions

- **Challenge:** Lovable mock had requisition as one-item-per-form. **Resolution:** Confirmed multi-line invoice-style header + lines pattern.
- **Challenge:** "10 sent / 8 received" disputes — should they edit? **Resolution:** Locked — disputed records permanent, corrective transfer creates new linked record.
- **Challenge:** No memory tool available for `/memories/handoff.md`. **Resolution:** Wrote handoff to this file in the workspace docs.
- **Blocker:** DevOps pipeline (beta ↔ main auto-merge concern from user). **Status:** Unresolved, awaiting user to either bring DevOps engineer in or point to pipeline config files for audit before `feature/ims` branch is cut.

## Files Modified / Created This Session

- `cedibites_api/.github/instructions/ims-considerations.instructions.md` — NEW (locked decisions, workflow rules, isolation rules, out-of-scope list)
- `cedibites/.github/instructions/ims-considerations.instructions.md` — NEW (frontend mirror)
- `cedibites_api/.github/instructions/code-quality.instructions.md` — NEW (file caps, engine pattern, anti-patterns, pre-commit checklist)
- `cedibites/.github/instructions/code-quality.instructions.md` — NEW (frontend mirror with TS-specific caps)
- `cedibites_api/.github/agents/inventory-auditor.agent.md` — NEW (canonical agent)
- `cedibites/.github/agents/inventory-auditor.agent.md` — NEW (frontend mirror)
- `cedibites_api/docs/agents/inventory-auditor-kb.md` — NEW (institutional memory, §1–§9 sections initialized)
- `cedibites_api/docs/inventory/architecture.md` — NEW (formal architecture doc, ERD, engines, roadmap)
- `cedibites_api/docs/inventory/SESSION_HANDOFF.md` — NEW (this file)

## Current State

- **Architecture**: locked and documented
- **Code**: zero IMS code written yet
- **Branches**: not yet cut — `feature/ims` to be created in both repos when user gives signal
- **Permissions**: documented in KB §3, not yet registered
- **Feature flag**: documented, not yet implemented
- **Master Orchestrator + 7 specialist agents** are aware of IMS via the always-on instruction file

## Next Steps (Ordered)

1. **DevOps pipeline audit** — verify beta/main auto-merge behavior, confirm staging exists with prod-snapshot capability. User must surface DevOps engineer or point to pipeline config files (`.github/workflows/`, deploy scripts).
2. **Update both `PROJECT_CHRONICLE.md` files** with this session's decisions (was on TODO, deprioritized due to context). Project Chronicle agent can do this in next session.
3. **User signal to begin Phase 0** — once DevOps is clear:
    - Cut `feature/ims` branch in both repos
    - Generate Phase 0 backlog (scaffold, feature flag, route group, permissions registration, locations CRUD, items/categories/units/suppliers CRUD, warehouse-portal three-section layout)
    - Delegate via Master Orchestrator: IAM Auditor (permissions), Inventory Auditor (backend scaffold), UX Architect (portal shell), Project Chronicle (record decisions)
4. **Phase 0 → 1 → 2 → 3 → 4** as per [architecture.md §13](architecture.md)

## Important Context

- **Transcript files were read in full** — both CBIMS and CBIMS 2 transcripts were attached and parsed, with 17 specific nuances surfaced (see message exchange). Sections 12–15 of user's summary are explicitly out-of-scope.
- **Operator vocabulary matters** — "mother kitchen" / "satellite kitchen" / etc. UI copy must use these terms. Code can stay neutral.
- **Simplicity is a product principle** — encoded in instruction file. Branch Manager Test: 30-second comprehension by non-technical operator.
- **Two new roles introduced**: `Purchasing Clerk` and `Warehouse Manager` — IAM Auditor must register their permissions in Phase 0.
- **Ingredient deduction is the highest-risk integration** — touches Order completion path. Phase 3 work; Order Auditor must confirm `OrderCompleted` event payload includes `branch_id` and line items with `menu_item_id` + `quantity`.
- **Stock Ledger report has FIXED columns** — do not invent variations.
- **Wastage threshold default is ₵500 GHS** — configurable per location via `inventory_settings`.

## Workspace & Branch Info

- Workspace folders:
    - `c:\Users\iamjn\Desktop\WEBZ\CediBites\cedibites` — Frontend (Next.js 16). Branch: `main`. Default: `main`.
    - `c:\Users\iamjn\Desktop\WEBZ\cedibites_api\cedibites_api` — Backend (Laravel 12). Branch: `master`. Default: `master`.
- No `feature/ims` branches exist yet. Do NOT cut them until DevOps audit clears.
- No running processes started this session.

## How to Resume

1. Read this file fully.
2. Read [`cedibites_api/docs/inventory/architecture.md`](architecture.md).
3. Read [`cedibites_api/docs/agents/inventory-auditor-kb.md`](../agents/inventory-auditor-kb.md).
4. Confirm with user:
    - DevOps pipeline status (gating Phase 0 kickoff)
    - Whether to update PROJECT_CHRONICLE.md files now
    - Whether to begin Phase 0 backlog generation
5. Proceed via Master Orchestrator decomposition + delegation protocol.
