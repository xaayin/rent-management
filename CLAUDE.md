# CLAUDE.md — Council Land & Property Lease Management System

This file is guidance for Claude Code. Read it fully before writing code. The complete
requirements are in `docs/PRD.md`; the slice-by-slice build order is in `docs/BUILD_PLAN.md`.

## What we are building

A web system for a council to manage leases of land and premises. It replaces a manual Excel
register. Core jobs: keep a register of properties, tenants and leases; generate rent invoices
automatically each month; apply configurable late-payment fines; record payments and issue
receipts; and send SMS/email payment reminders. Users are council staff (role-secured); tenants
only receive notices in this release.

**Read `docs/PRD.md` for the authoritative requirements.** When a requirement ID is referenced
here or in a task (e.g. `FR-FIN-01`), find it in the PRD and implement to that.

## Technology stack (decided — do not substitute without being asked)

- **Laravel (PHP)** — latest stable, `declare(strict_types=1);` in PHP files.
- **Livewire** — server-rendered reactive UI (the TALL stack). Use the official Livewire starter kit.
- **Tailwind CSS** + **Alpine.js** — styling and light interactivity.
- **Filament** — MAY be used to accelerate back-office CRUD/dashboards/RBAC screens. Ask before
  introducing it if a slice can be done simply with plain Livewire; don't assume.
- **PostgreSQL** — primary database.
- **Laravel Scheduler + Queues** — for invoice generation and reminders (queue driver: database to start).
- **Laravel Notifications** — with a custom SMS channel for reminders.
- **Laravel Fortify** — auth incl. 2FA for privileged roles.
- **Pest** — tests. **Laravel Pint** — formatting.

### Packages
- `spatie/laravel-permission` — roles & permissions (§6).
- `spatie/laravel-activitylog` (or `owen-it/laravel-auditing`) — audit trail (§4.10).
- `spatie/laravel-pdf` — invoice/receipt PDFs (`barryvdh/laravel-dompdf` acceptable fallback).

## Hard rules (non-negotiable)

1. **Money is never a float.** Store all monetary values as **integer minor units (laari)**:
   `1 rufiyaa (MVR) = 100 laari`. Do all arithmetic in integer laari; format to MVR only at the
   view/PDF/SMS boundary. This governs rent, CSR charges and every fine calculation.
2. **Financial records are append-only.** Never hard-delete invoices, payments or receipts.
   Corrections are reversing entries, with a reason, captured in the audit trail.
3. **Business logic lives in services, not in Livewire components or controllers.** The invoice
   generator and the fine calculator are plain, unit-tested PHP classes with no framework
   coupling in their core logic. UI and jobs call into them.
4. **Authorize on the server for every action** (policies / `spatie` permissions), not just by
   hiding UI. Match the permission matrix in PRD §6.1.
5. **The whole system is in English** — UI, invoices, receipts, notifications. No localization layer.
6. **Idempotent scheduled jobs.** Exactly one invoice per lease per billing cycle; a re-run must
   not double-invoice or double-charge (FR-INV-06).
7. **Tests first for money-critical code** (fine engine, invoicing). Use the worked examples in the
   PRD as test cases before implementing.

## Locked decisions (the PRD left these open — use these defaults; change only if asked)

- **Currency / storage:** MVR, stored as integer laari (minor units).
- **App timezone:** `Indian/Maldives` (UTC+5). All due-date and late-day maths in this timezone.
- **Billing:** monthly; due day configurable per lease, default the 10th.
- **Invoice / receipt numbering:** sequential per year, format `YYYY/NNN` (zero-padded), never reused.
- **Payment allocation:** rent first, then fine (configurable later).
- **Tiered fine — partial month:** each commenced overdue month counts as one full month.
- **SMS provider:** not yet supplied. Implement the SMS channel behind an interface with a
  **log/null driver** for now, plus a config slot for the real gateway. Do not hardcode a provider.

## Domain glossary (shorthand)

- **Property** — a leasable land parcel/premises (name, land no., size ft², usage type).
- **Tenant** — Individual (national ID) or Organisation (company reg no.).
- **Lease** — property + tenant + terms (rate basis, dates, status, fine rule).
- **FineRule** — per-lease config: method + parameters (see below), effective-dated.
- **Invoice** → **Payment** → receipt. Fine accrues on overdue invoices.

### Fine methods (FR-FIN-01, business rules §5.3) — all three must be supported
- `flat_per_day` — fine = late_days × flat_amount.
- `percent_per_day` — daily = percent × base; fine = late_days × daily. base = rent, or rent+charges.
- `tiered_monthly` — first overdue month = first_month_amount (default 10000 laari = MVR 100);
  each subsequent overdue month = subsequent_month_amount (default 5000 laari = MVR 50).
  For n overdue months: `first + (n-1) × subsequent`. Amounts editable per lease.
Fines are added to the invoice automatically and shown itemised (method, dates, days/months,
per-tier amounts, total) — FR-FIN-11/12.

## Project conventions

- Structure domain logic under `app/Services/` (e.g. `Billing/InvoiceGenerator`,
  `Billing/FineCalculator`), scheduled work under `app/Jobs/`, notifications under
  `app/Notifications/`, models under `app/Models/`.
- Migrations define exact types: money columns are `bigInteger` (laari); dates are `date`/`datetime`.
- Enums (fine method, lease status, tenant type) as PHP backed enums.
- One Pest test file per service; name tests by behaviour. Keep fine/invoice coverage high.

## Commands

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run dev                 # Vite / Tailwind
php artisan serve           # http://localhost:8000
php artisan queue:work      # process queued jobs (reminders, etc.)
php artisan schedule:work   # run the scheduler locally (invoice runs, reminders)
./vendor/bin/pest           # run tests
./vendor/bin/pint           # format
```

## Working agreement

- Build in **vertical slices** in the order in `docs/BUILD_PLAN.md`. Don't attempt the whole PRD at once.
- For each slice: confirm the plan, write/adjust migrations and models, write tests for any money
  logic first, implement, run `pest` and `pint`, then stop for review.
- If a requirement is ambiguous, check the PRD; if still unclear, ask rather than guessing — except
  where a default is listed above.
