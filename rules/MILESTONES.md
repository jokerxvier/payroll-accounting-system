# MILESTONES.md — Payroll & Accounting System (v1)

> Client-facing companion to `rules/PLAN.md`. PLAN.md is the engineering contract; this file is the high-level view of gates, deliverables, and status. If the two ever disagree, PLAN.md wins.

**Timeline:** 16 weeks · **Approach:** 4 gated phases · **Sign-off:** written approval at every gate

Status legend: ✅ Done · 🟡 In progress · ⬜ Pending

---

## Status as of 2026-05-07

The engineering scope through Gate 3 is complete. The team is inside Gate 4 (launch readiness): performance pinning has shipped; the remaining work is the accessibility pass, client UAT, cross-browser smoke, and the production cutover.

| Gate | Window | Status | Sign-off owner |
|---|---|---|---|
| 1 — Foundation & Employee Management | Weeks 1–4 | ✅ Done (client demo + 2 stragglers outstanding) | Client + Dev |
| 2 — Payroll Computation Engine | Weeks 5–8 | ✅ Done (client demo outstanding) | Client (HR/Finance) + Dev |
| 3 — Batch Processing & Documents | Weeks 9–12 | ✅ Done (client demo outstanding) | Client + Dev |
| 4 — Reports, Audit & Launch | Weeks 13–16 | 🟡 In progress | Client |

---

## Gate 1 — Foundation & Employee Management (End of Week 4)

**Outcome:** A deployable shell with authenticated users, the LMS bridge proven, and the Employee Management module working end to end.

**Client-visible deliverables**
- Authentication against the existing LMS user table; five roles seeded (super-admin, payroll-officer, HR, auditor, employee).
- Read-only LMS bridge — writes to any LMS table raise an exception (defense in depth).
- Employee directory with filters, search, and pagination.
- Employee profile page with inline editing of payroll-owned fields (no LMS writes).
- Audit log captures actor, before/after, IP, and user-agent on every employee-profile change.

**Status:** ✅ Done — outstanding items: Forge staging/production environments not yet provisioned (client infra dependency); 200 ms directory perf measurement still owed; formal client demo sign-off pending.

---

## Gate 2 — Payroll Computation Engine (End of Week 8)

**Outcome:** Compute one employee's payroll for one period, end to end, including all Philippine statutory contributions, deductions, loans, allowances, and adjustments — with a real-time gross-to-net preview.

**Client-visible deliverables**
- Effective-dated contribution tables for BIR, SSS, PhilHealth, and Pag-IBIG with a super-admin admin UI for new rate versions.
- Computation engine validated against 10 hand-computed reference cases to the centavo.
- Deductions, loans, allowances, and one-off adjustments framework, all surfaced on the employee profile.
- Real-time preview page: pick employee + period, see gross-to-net update in under 500 ms server compute (perf-pinned).
- Zero floating-point arithmetic in any payroll path (architecturally enforced).

**Status:** ✅ Done — all 6 acceptance criteria ticked in PLAN.md §5 Phase 2. Awaiting client demo sign-off.

---

## Gate 3 — Batch Processing & Documents (End of Week 12)

**Outcome:** Run payroll for the entire company in one operation, approve and lock the period, and produce payslips as PDFs in bulk. Bulk employee-data movement via Excel.

**Client-visible deliverables**
- Pay periods and payroll runs with explicit lifecycle (draft → computed → approved → posted → voided).
- Approval workflow with period locking — approved runs are immutable; corrections require voiding and re-running.
- Single payslip view (HTML) and download (PDF), screen and print parity verified.
- Bulk payslip PDF generation packaged as a downloadable zip per run.
- Excel import for employee data with row-level validation, preview-diff, and confirm-to-apply (nothing writes on partial failure).
- Email payslip distribution (queued, configurable per run).

**Status:** ✅ Done — all 6 acceptance criteria pinned by `Phase3AcceptanceTest`. Awaiting client demo sign-off.

---

## Gate 4 — Reports, Audit & Launch (End of Week 16)

**Outcome:** Reports module, audit log viewer, end-to-end polish, and production cutover.

**Client-visible deliverables**
- Three reports (payroll summary, employee history, audit log) exportable to Excel, CSV, and PDF.
- Audit log viewer with filters (actor, action, date, target) and a before/after diff drawer.
- WCAG AA accessibility verification on critical pages; cross-browser smoke (Chrome, Edge, Safari, Firefox).
- Client UAT against sanitized real data; performance audit and bug triage.
- HR/payroll user guide and admin runbook (rate-table updates, role management, restore from backup).
- Production deployment with documented cutover and a 1-week post-launch hypercare period.

**Status:** 🟡 In progress — performance pinning across W13/W14/W15 shipped; the accessibility pass, UAT, cross-browser smoke, and W16 cutover are ahead. 0 of 5 phase acceptance criteria ticked.

---

## Client Dependencies

Items the client owes the project. A slip of more than one week on any of these shifts the corresponding gate.

| Dependency | Needed by | Status |
|---|---|---|
| Read-only LMS DB credentials | Week 1 | ✅ Provided |
| Sample employee export (≥ 20 records) | Week 1 | ✅ Provided |
| Role matrix and approval workflow | Week 2 | ✅ Provided |
| Current statutory contribution tables (BIR, SSS, PhilHealth, Pag-IBIG) | Week 4 | ✅ Provided |
| Hand-computed reference payroll cases (10+) | Week 5 | ⬜ Pending — using internal reference set; client cases will replace |
| Standardized payslip format mockup | Week 9 | ✅ Provided |
| UAT participants and schedule | Week 13 | ⬜ Pending |
| Production environment access (Forge, DNS, SSL) | Week 14 | ⬜ Pending |

---

## Out of Scope for v1

Explicitly deferred — not failures, scope decisions. See `rules/PLAN.md` §2 (Non-Goals) and §11 (Accounting Side) for full rationale.

- General Ledger / journal entries — the **Accounting** half of "Payroll & Accounting" ships as a later phase of this same project, not v1.
- Government e-filing integrations (BIR EFPS, SSS R3, PhilHealth EPRS).
- Year-end tax annualization and BIR Form 2316 generation.
- Loan amortization scheduling, biometric time-and-attendance, multi-company payroll, and a native mobile app.

---

## Timeline at a Glance

```
Month 1  W1–W4   Foundation & Employee Management         [Gate 1] ✅
Month 2  W5–W8   Payroll Computation Engine               [Gate 2] ✅
Month 3  W9–W12  Batch Processing & Documents             [Gate 3] ✅
Month 4  W13–W16 Reports, Audit, Polish & Launch          [Gate 4] 🟡
Week 17          Hypercare (post-launch, outside the plan)
```

---

## Where to Dig Deeper

- `rules/PLAN.md` — full 16-week engineering plan with weekly task lists, acceptance criteria, risks, and open questions.
- `rules/CODING_STANDARDS_LARAVEL.md` and `rules/CODING_STANDARDS_REACT.md` — implementation conventions.
- `rules/THEME.md` and `rules/RULES.md` — visual design and UI implementation rules.
