# CLAUDE.md — Council Land & Property Lease Management System

This file is guidance for Claude Code. Read it fully before writing code. The complete
requirements are in `docs/PRD.md` (with as-built decisions in its Appendix B); the delivery
history is in `docs/BUILD_PLAN.md`; the UI system is in `docs/UI_DESIGN_PRD.md` (§11 covers
what was actually built).

## What we are building

A web system for a council to manage leases of land and premises. It replaces a manual Excel
register ("Kuli Binthakuge Dhaftaru"). Core jobs: keep a register of properties, tenants and
leases; generate rent invoices automatically each month; apply configurable late-payment fines;
record payments and issue receipts; and send SMS payment reminders. Users are council staff
(role-secured); tenants only receive notices in this release.

**Status: build-plan Slices 0–8 are complete** (registry, RBAC, auto-invoicing, fine engine,
payments/receipts/statements/PDFs, SMS reminders, dashboard/reports/exports, legacy import from
the real workbook). See "Deferred backlog" below for what remains.

**Read `docs/PRD.md` for the authoritative requirements.** When a requirement ID is referenced
(e.g. `FR-FIN-01`), find it in the PRD and implement to that.

## Technology stack (as built — do not substitute without being asked)

- **Laravel 13 (PHP 8.3+)**, `declare(strict_types=1);` in PHP files.
- **Livewire v4** — class-based components (`php artisan make:livewire name --class`), NOT
  single-file components. Full-page components with `#[Layout('components.layouts.app')]`.
- **Tailwind CSS v4** (CSS-first `@theme` in `resources/css/app.css`) + design-system component
  classes in `@layer components`. Fonts self-hosted via `laravel-vite-plugin` bunny provider
  (**Inter**, loaded with `{{ Vite::fonts() }}` in layouts).
- **MySQL** — primary database (user decision; overrides the PRD's PostgreSQL default).
  Database `rent_db`. **Tests run on in-memory SQLite** — write driver-portable SQL, or branch
  on `DB::connection()->getDriverName()` (see `ReportService::incomeByMonth`).
- **Laravel Scheduler + Queues** (database driver).
- **Laravel Notifications** with a custom SMS channel (`App\Notifications\Channels\SmsChannel`).
- **Laravel Fortify** — auth; public registration disabled (staff are admin-created); 2FA
  columns/trait in place, enrolment UI not built yet.
- **Pest** — tests. **Laravel Pint** — formatting. Run both at the end of every task.

### Packages (installed)
- `spatie/laravel-permission` — roles & permissions (§6).
- `spatie/laravel-activitylog` **v5** — audit trail. Note v5 namespaces:
  `Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions`,
  and `dontLogEmptyChanges()` (not `dontSubmitEmptyLogs()`).
- `spatie/laravel-pdf` + `spatie/browsershot` — invoice/receipt/report PDFs. Requires the
  project-local `puppeteer` npm package and a downloaded `chrome-headless-shell`
  (`npx puppeteer browsers install chrome-headless-shell`).
- `openspout/openspout` — reads the council's real xlsx register for `import:register`.

## Hard rules (non-negotiable)

1. **Money is never a float.** All monetary values are **integer minor units (laari)**:
   1 rufiyaa (MVR) = 100 laari. All arithmetic through `App\Support\Money` (immutable VO with
   `fromLaari`/`fromRufiyaa`/`toRufiyaa`/`format`); format to MVR only at the view/PDF/SMS
   boundary. Percentages are stored as **integer basis points** (0.5%/day = 50 bps).
2. **Financial records are append-only.** `Payment` and `Invoice` models throw on delete;
   `Payment` throws on ANY update (receipt numbers immutable). Corrections are reversing
   entries (negative payment rows) with a mandatory reason.
3. **Business logic lives in services** (`app/Services/Billing`, `Reminders`, `Reporting`,
   `Import`, `Sms`), not in Livewire components or controllers. Livewire components hold only
   form state, validation and authorization.
4. **Authorize on the server for every action** — route `can:` middleware AND in-component
   `$this->authorize()` / policy checks, matching PRD §6.1. Hiding UI is never enough.
5. **English only** — UI, invoices, receipts, notifications. (Imported registry data may
   contain Thaana text — that is data, not UI.)
6. **Idempotent scheduled jobs.** Exactly one invoice per lease per cycle (DB unique on
   lease+year+month); re-runs return the existing record. The importer upserts by natural keys.
7. **Tests first for money-critical code.** The fine-engine worked examples in the PRD are the
   canonical test cases; date maths is calendar-day granular (normalise with `startOfDay()` —
   a time of day must never tip an exact-month boundary).

## Key implementation decisions (as built — follow these)

- **Approval-gated actions (§6.1 "A" cells)**: modelled as permission *pairs* — a base
  permission (`terminate leases`, `waive fines`, `reverse payments`) lets a role initiate, and
  a `… without approval` variant lets it act directly. Helpers on `User`: `mayInitiate()`,
  `mayActWithoutApproval()`, `requiresApprovalFor()`. Until the approvals workflow exists,
  only direct actors (Supervisor) can perform these actions.
- **Fine engine**: `FineCalculator` returns a `FineBreakdown` DTO (full itemisation, FR-FIN-12).
  Rules are effective-dated append-only rows; the rule applied is the one in force at the
  invoice's **issue date**. The fine accrues daily while principal is outstanding and
  **freezes** once principal is settled. A lease with **no fine rule accrues no fine** (no
  system-wide default until the council states one).
- **Payments**: allocation is rent(principal incl. CSR)-first then fine, via
  `config/billing.php` (`fine_first` also implemented). The fine is recomputed **as of the
  actual payment date** before allocating. Overpayments are rejected (no credit balances).
- **Invoice/receipt numbering**: `YYYY/NNN` from locked per-year counter tables
  (`invoice_sequences`, `receipt_sequences`) — separate sequences, never reused.
- **SMS**: `SmsSender` interface with `log` (default), `null` and `msgowl` drivers
  (`config/sms.php`; MsgOwl: POST `{endpoint}/messages`, `Authorization: AccessKey …`,
  recipients as bare digits, 429 retry with back-off). The channel logs EVERY attempt to
  `notification_logs`; failed sends retry on the next scheduled run.
- **Legacy import**: `import:register {file} {--dry-run}` accepts the real workbook (`.xlsx`,
  via `WorkbookRegisterReader` — Thaana dates/rates/CSR, grace from leased-vs-rent-start,
  synthesised land numbers with parcel-splitting on active clashes) or the canonical CSV.
  Data-quality gaps (missing mobile/registry) import with **warnings**, not rejections.
  The reconciliation report's monthly-rent total must tick against the workbook.
- **Historical ledgers NOT imported** (receipt-number continuation is an open council question).

## Domain glossary (shorthand)

- **Property** — a leasable parcel (name, land no., size ft², usage type, Active/Archived).
- **Tenant** — Individual (national ID `A…`) or Organisation (company reg `C-…`). May lack a
  registry number when imported from the register (keyed by name+mobile), flagged for cleanup.
- **Lease** — property + tenant + terms (rent basis per-ft²|flat, grace months, due day,
  CSR config, status Draft/Active/Terminated/Expired, notes).
- **FineRule** — per-lease, effective-dated: `flat_per_day` | `percent_per_day` (bps) |
  `tiered_monthly` (defaults MVR 100 first / MVR 50 subsequent).
- **Invoice** → **Payment** (receipt) → reversal. Line items carry a `meta` breakdown.

## UI conventions (as built — every new screen must follow these)

The visual source of truth is `design/ui-prototype.html` (ADS/Jira idiom) and
`docs/UI_DESIGN_PRD.md`. As built:

- **Design tokens + component classes** live in `resources/css/app.css`: `btn-primary`,
  `btn-subtle`, `btn-danger`, `icon-btn`, `nav-item(-active)`, `loz` + `loz-{neutral,success,
  warning,danger,info,discovery}`, `ava`, `chip`, `tab(-active)`, `th`/`td`, `fl`/`fl-req`/`fv`,
  `input`, `menu-row`, `stat`, `pg(-active)`. **Never hardcode colours/radii/shadows** — use
  tokens/classes. Type-scale utilities `text-11 … text-24`.
- **Shared Blade components**: `<x-modal :title close :wide>` (Jira modal; backdrop click
  closes; pair with `wire:keydown.escape.window` on the page root), `<x-pagination
  :paginator>` (issue-list footer "Showing 1–10 of N" + windowed page buttons),
  `<x-toast />` (session('status') flash → dark bottom-centre pill, CSS auto-dismiss —
  place INSIDE the Livewire root so it renders on component updates).
- **List-page pattern** (Leases/Invoices/Tenants/Properties all follow it): breadcrumb →
  header (title + one `btn-primary`) → toolbar (debounced search input + chip-styled
  `<select>` filters + "Clear filters" + right-aligned count) → optional tabs → issue-list
  table in a card (outer `overflow-hidden`, inner `overflow-x-auto` so nothing is ever
  clipped) → `<x-pagination>`. Filters/tabs are `#[Url]` Livewire properties; **any filter
  change calls `resetPage()`**; ordering is deterministic (`latest()->orderByDesc('id')` or
  name + id tiebreak). Page size 10. Filter-aware empty states.
- **Create/edit forms are modals** (`<x-modal>`, `:wide="true"` for long forms like the
  lease). The top-bar **Create menu** deep-links with `?create=1` (handled in `mount()`).
- **Leases workspace**: row click opens a **slide-over** (max 560px) with amount-due banner,
  field grid, fine-rule card (show the maths), recent invoices, activity timeline, and a
  permission-gated action bar (Record payment / Send reminder / Fine rule / Terminate / Edit).
  The record-payment modal shows the live rent/fine allocation for the chosen payment date.
- **Money display**: right-aligned, `tabular-nums`, formatted by `Money::format()`.
  **Status**: always a lozenge with a text label, mapped per UI_DESIGN_PRD §3.1 — never
  colour alone. One primary (blue) button per view.
- **Nav/actions are permission-gated** with `@can` AND server-side authorization. The §6.1
  matrix means e.g. Admin sees Invoices but no Record-payment; Finance can't open /leases.

## Project structure

- Services: `app/Services/Billing/` (InvoiceGenerator, InvoiceNumberGenerator, FineCalculator,
  FineBreakdown, InvoiceFineApplier, PaymentRecorder, ReceiptNumberGenerator), `Reminders/`,
  `Reporting/ReportService`, `Import/`, `Sms/`.
- Livewire pages: `app/Livewire/{Dashboard,Leases,Invoices,Tenants,Properties,Reports,Settings}`.
  Shared form logic in `app/Livewire/Concerns/InteractsWithPayments`.
- Jobs: `GenerateInvoices`, `SendPaymentReminders`. Commands: `leases:expire`,
  `invoices:generate`, `invoices:refresh-fines`, `import:register`.
- Schedule (routes/console.php): 00:05 expire · monthly 1st 00:10 invoices · 00:15 fines ·
  09:00 reminders.
- Enums in `app/Enums/` (backed, with `label()`); policies per model in `app/Policies/`;
  migrations use `bigInteger` laari money columns.
- PDFs: `resources/views/pdf/*` (self-contained inline CSS, no Vite).

## Commands

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # seeds roles, reminder rules, demo accounts + demo registry
npm run dev                     # or: npm run build
php artisan serve
php artisan queue:work
php artisan schedule:work
./vendor/bin/pest               # test suite (~240 tests) — must stay green
./vendor/bin/pint               # format — run before finishing any task

# Operations
php artisan invoices:generate --period=2026-07
php artisan invoices:refresh-fines --as-of=2026-07-10
php artisan import:register storage/app/import/register.xlsx --dry-run
```

Demo sign-ins (password `password`): admin@ / supervisor@ / land@ / finance@ / auditor@
example.com — each sees only what §6.1 allows; supervisor has the widest UI.

## Deferred backlog (flag these when relevant; do not silently re-scope)

- Supervisor-approval workflow for the §6.1 "A" actions (Finance waive/reverse, Land Officer
  terminate) — permission model is ready, workflow is not.
- Fine waivers + FR-RPT-06 fine report (accrued/collected/waived).
- Historical ledger import (old invoices/receipts; receipt-sequence continuation = PRD §14.4.37).
- Email channel (INT-EML-01); MsgOwl delivery-status callbacks; 2FA enrolment UI.
- Proration (FR-INV-08); admin-configurable usage types (FR-PRP-04); per-lease/tenant reminder
  overrides (FR-NOT-04); tenant-detail slide-over on the Tenants page.

## Working agreement

- Work in reviewable increments; confirm a short plan before large changes and stop for review after.
- Write/adjust Pest tests for any money logic FIRST; keep the suite green; run `pint` at the end.
- If a requirement is ambiguous, check the PRD (+ its Appendix B); if still unclear, ask rather
  than guessing — except where a default is listed above.
- Nothing here has been committed to git without an explicit request from the user.
