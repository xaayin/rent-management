# Build Plan — vertical slices for Claude Code

Build the system in the order below. Each slice is independently reviewable and ends with tests
passing. Give Claude Code **one slice at a time** using the prompt provided, and review before
moving on. All slices assume the rules in `../CLAUDE.md` and the requirements in `PRD.md`.

> Convention: money is stored as **integer laari** (1 MVR = 100 laari). See CLAUDE.md → Hard rules.

---

## Slice 0 — Project scaffold

**Goal:** a running Laravel + Livewire app with the toolchain and packages wired in.

**Prompt:**
> Set up a new Laravel project using the official Livewire starter kit (Tailwind + Alpine, Fortify
> auth). Configure PostgreSQL, the database queue, and the `Indian/Maldives` timezone. Install and
> configure Pest, Pint, `spatie/laravel-permission`, `spatie/laravel-activitylog`, and
> `spatie/laravel-pdf`. Add a `Money` value object (integer laari) with `fromRufiyaa`, `toRufiyaa`,
> and formatting helpers, with Pest tests. Confirm `pest` and `pint` run clean.

**Done when:** app serves, auth works, `pest` green, `Money` VO tested.

---

## Slice 1 — Users, roles & permissions (PRD §6)

**Goal:** RBAC matching the permission matrix.

**Prompt:**
> Implement roles and permissions per PRD §6.1 using `spatie/laravel-permission`. Create the roles
> (Administrator, Finance/Revenue Officer, Land/Lease Officer, Supervisor, Auditor) and seed the
> permissions from the §6.1 matrix. Add policies enforcing them server-side. Add a Livewire user &
> role management screen restricted to Administrators. Write Pest tests asserting each role can/can't
> do the actions in the matrix (including "with approval" cases).

**Done when:** matrix enforced in tests; non-permitted actions are blocked server-side.

---

## Slice 2 — Registry: Properties, Tenants, Leases (PRD §4.1–4.3, §9)

**Goal:** the core records and their CRUD.

**Prompt:**
> Create models, migrations and Livewire CRUD for Property, Tenant and Lease per PRD §4.1–4.3 and the
> data model in §9. Tenant is Individual or Organisation (backed enum) with the right identifiers
> (FR-TEN-01). Lease links one Property to one Tenant with agreement no., dates, duration, status
> (backed enum: Draft/Active/Terminated/Expired) and rent basis. Enforce: unique agreement number
> (FR-LSE-06), no two active leases on one parcel (FR-PRP-02), auto-expire past expiry (FR-LSE-03).
> Seed a few records mirroring the PRD examples. Pest tests for the invariants.

**Done when:** can create/list/edit all three; invariants covered by tests.

---

## Slice 3 — Charge configuration + invoice generation (PRD §4.4, §4.5, §5.1–5.2)

**Goal:** invoices generated automatically from lease terms.

**Prompt:**
> Implement rent/charge configuration per §4.4: rent basis per-ft²×area OR flat monthly, grace
> period, optional CSR (fixed annual or % of revenue), billing cycle + due day. Build an
> `InvoiceGenerator` service and a scheduled `GenerateInvoices` job that creates exactly one invoice
> per active lease per cycle (idempotent — FR-INV-06), respecting grace/start/expiry (FR-INV-04),
> with sequential `YYYY/NNN` numbering. Invoices itemise rent + CSR. Store amounts in laari. Write
> Pest tests including: 2,000 ft² × 53 laari = MVR 1,060.00/month; grace period suppresses invoices;
> re-running the job does not duplicate.

**Done when:** monthly run produces correct, non-duplicated invoices; tests green.

---

## Slice 4 — Fine engine (PRD §4.7, §5.3) — TDD

**Goal:** all three fine methods, configurable per lease, fully itemised.

**Write these Pest tests first, then implement `FineCalculator`:**

| Case | Setup | Expected |
|---|---|---|
| Percent/day | rent 500 MVR, 0.5%/day, base=rent, 422 days late | fine = MVR 1,055.00; total = MVR 1,555.00 |
| Flat/day | rent 500 MVR, flat 3.75/day, 422 days late | fine = MVR 1,582.50 |
| Tiered — 1 mo | first=100, subsequent=50, 1 month late | fine = MVR 100.00 |
| Tiered — 2 mo | same, 2 months late | fine = MVR 150.00 |
| Tiered — 3 mo | same, 3 months late | fine = MVR 200.00 |
| Not late | any method, paid on/before due date | fine = 0 |

**Prompt:**
> Implement a `FineCalculator` service supporting the three methods in PRD §4.7 / §5.3
> (`flat_per_day`, `percent_per_day`, `tiered_monthly`), configured per lease via a `FineRule`
> (effective-dated), all amounts in laari. Partial overdue months count as one full month. Support
> allowance days, base (rent vs rent+charges), and an optional cap. Return a structured breakdown
> (method, set/due date, late days or months, per-tier lines, total) so it can be shown on the
> invoice (FR-FIN-12). Make the tests in the table above pass first, then wire the fine onto overdue
> invoices and recompute daily for unpaid ones (FR-FIN-04/05).

**Done when:** all table cases pass; changing one lease's rule doesn't affect others.

---

## Slice 5 — Payments, receipts & statements (PRD §4.6, §5.4)

**Prompt:**
> Implement payment recording against invoices (FR-PAY-01): amount, date, method, reference,
> auto receipt number. Compute fine on the actual payment date and allocate rent-first then fine
> (§5.4). Support partial payments and status transitions Paid/Partly paid/Overdue. Produce a
> per-tenant statement and a printable PDF invoice/receipt showing the full fine breakdown. Payments
> are append-only; corrections are reversals with a reason (FR-PAY-06). Pest tests for allocation,
> partials, and that receipts can't be edited after issue.

**Done when:** payments update balances correctly; statement + PDF render; reversals audited.

---

## Slice 6 — Notifications & reminders (PRD §4.8, §8.1)

**Prompt:**
> Implement reminders via Laravel Notifications with a custom SMS channel behind an interface, using
> a **log/null driver** for now (no live provider yet) plus config for the real gateway. Add
> configurable schedules (X days before due, on due, Y days after — FR-NOT-02) and editable English
> templates with merge fields (FR-NOT-03). A scheduled job sends due reminders and logs every send
> with status (FR-NOT-05); never remind on paid invoices (FR-NOT-07). Add a manual "send reminder
> now" action. Pest tests using a fake SMS driver assert the right messages are queued and logged.

**Done when:** reminders schedule and log correctly against the fake driver; templates render.

---

## Slice 7 — Dashboards & reports (PRD §4.9)

**Prompt:**
> Build the dashboard (active leases, billed/collected this month, arrears, fines outstanding —
> FR-RPT-01), an arrears/aging view, upcoming expiries, and an income report. Add Excel/CSV and PDF
> export (FR-RPT-05). Keep queries efficient. Basic tests for the totals.

**Done when:** dashboard and arrears reflect seeded data; exports work.

---

## Slice 8 — Legacy data migration (PRD Appendix A)

**Prompt:**
> Build a repeatable import command that reads the existing spreadsheet and maps columns to the new
> model per PRD Appendix A, with validation and a reconciliation report (counts, totals, rejected
> rows). Do a dry-run mode. Tests on a small fixture.

**Done when:** import runs on a sample, reports cleanly, and is re-runnable.

---

## Cross-cutting (apply throughout)
- Audit-log create/update/reverse on leases, tenants, invoices, payments, fines, config, users (§4.10).
- Run `pest` and `pint` at the end of every slice.
- Keep the invoice generator and fine calculator free of framework coupling in their core logic.
