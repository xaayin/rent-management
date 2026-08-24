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
(role-secured); tenants get SMS notices plus a read-only self-service portal (`/portal`).

**Status: build-plan Slices 0–8 are complete** (registry, RBAC, auto-invoicing, fine engine,
payments/receipts/statements/PDFs, SMS reminders, dashboard/reports/exports, legacy import from
the real workbook), plus post-plan features: advance billing (multi-month/full-term invoices),
tenant drill-down workspace, the self-service account page with full 2FA enrolment, and the
supervisor-approval workflow for the §6.1 `A` actions.
See "Deferred backlog" below for what remains.

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
- **Laravel Fortify** — auth; public registration disabled (staff are admin-created). Full 2FA:
  enrolment UI at `/settings/profile` (QR + TOTP confirm + recovery codes, driven by Fortify's
  action classes, not its HTTP endpoints), login challenge view bound in FortifyServiceProvider.
  `AuthenticateSession` middleware is appended to the web group (bootstrap/app.php) so password
  changes / "log out other sessions" invalidate stale sessions.
- **Pest** — tests. **Laravel Pint** — formatting. Run both at the end of every task.

### Packages (installed)
- `spatie/laravel-permission` — roles & permissions (§6).
- `spatie/laravel-activitylog` **v5** — audit trail. Note v5 namespaces:
  `Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions`,
  and `dontLogEmptyChanges()` (not `dontSubmitEmptyLogs()`).
- `spatie/laravel-pdf` **v2** (driver-based) + `spatie/browsershot` — invoice/receipt/report
  PDFs. Locally the default `browsershot` driver needs the project-local `puppeteer` npm
  package and a downloaded `chrome-headless-shell`
  (`npx puppeteer browsers install chrome-headless-shell`). **The driver is swappable via
  `LARAVEL_PDF_DRIVER` (`config/laravel-pdf.php`) with no code change** — hosts without Node
  or Chrome (Laravel Cloud) must use `cloudflare`/`gotenberg`, or they fail with "Cannot find
  module 'puppeteer'". See `docs/DEPLOYMENT.md`. **Never switch to `dompdf`**: it cannot shape
  Thaana and doesn't support the letterhead layout.
- `openspout/openspout` — reads the council's real xlsx register for `import:register`.

## Hard rules (non-negotiable)

1. **Money is never a float.** All monetary values are **integer minor units (laari)**:
   1 rufiyaa (MVR) = 100 laari. All arithmetic through `App\Support\Money` (immutable VO with
   `fromLaari`/`fromRufiyaa`/`toRufiyaa`/`format`); format to MVR only at the view/PDF/SMS
   boundary. Percentages are stored as **integer basis points** (0.5%/day = 50 bps).
2. **Financial records are append-only.** `Payment` and `Invoice` models throw on delete;
   `Payment` throws on ANY update (receipt numbers immutable). Corrections are reversing
   entries (negative payment rows) with a mandatory reason. An invoice raised in error is
   **voided, never deleted** (`InvoiceStatus::Cancelled`) — see "Invoice cancellation" below.
3. **Business logic lives in services** (`app/Services/Billing`, `Reminders`, `Reporting`,
   `Approvals`, `Import`, `Sms`), not in Livewire components or controllers. Livewire components hold only
   form state, validation and authorization.
4. **Authorize on the server for every action** — route `can:` middleware AND in-component
   `$this->authorize()` / policy checks, matching PRD §6.1. Hiding UI is never enough.
5. **English only** — UI, invoices, receipts, notifications. (Imported registry data may
   contain Thaana text — that is data, not UI.)
6. **Idempotent scheduled jobs.** Exactly one LIVE invoice per lease per cycle (DB unique on
   the derived `invoices.period_key`, which is NULL once an invoice is cancelled); re-runs
   return the existing record. The importer upserts by natural keys.
7. **Tests first for money-critical code.** The fine-engine worked examples in the PRD are the
   canonical test cases; date maths is calendar-day granular (normalise with `startOfDay()` —
   a time of day must never tip an exact-month boundary).

## Key implementation decisions (as built — follow these)

- **Approval-gated actions (§6.1 "A" cells)**: modelled as permission *pairs* — a base
  permission (`terminate leases`, `waive fines`, `reverse payments`) lets a role initiate, and
  a `… without approval` variant lets it act directly. Helpers on `User`: `mayInitiate()`,
  `mayActWithoutApproval()`, `requiresApprovalFor()` — all use spatie's **non-throwing**
  `checkPermissionTo()`, because the approvals badge calls them from the app layout on every
  page render and `hasPermissionTo()` would 500 the whole UI on an unregistered permission.
- **Approvals workflow** (`App\Services\Approvals\ApprovalService`): a role that
  `requiresApprovalFor()` an action files an `ApprovalRequest` instead of acting; a role that
  `mayActWithoutApproval()` decides it from `/approvals`, and approving replays the action from
  the request's own reason/payload. Policies split the two questions — `terminate`/`reverse`
  = may START (initiator or direct actor), `terminateDirectly`/`reverseDirectly` = may act now.
  Gate buttons on the former, immediate execution on the latter. Guarantees worth keeping:
  one open request per subject+action (nullable-unique `pending_key`, portable to MySQL *and*
  SQLite — a partial index is not), approve re-checks under `lockForUpdate()` so two
  supervisors can't double-apply, and it fails cleanly if the subject moved on meanwhile.
  `waive_fine` is the third A-cell — add the `ApprovalAction` case + an `execute()` branch when
  fine waivers land.
- **Fine engine**: `FineCalculator` returns a `FineBreakdown` DTO (full itemisation, FR-FIN-12).
  The rule applied is the one whose **period contains the invoice's anchor date** —
  `FineRuleResolver` is the ONE place that resolves it, and the anchor is config
  (`billing.fine_rule_anchor`, env `BILLING_FINE_RULE_ANCHOR`, `FineRuleAnchor` enum). Default
  and council rule is `period_start`: **the month the invoice bills, NOT when the row was
  created** — historic paperwork is routinely back-entered, so `created_at` would fine a
  Dec 2025 invoice under today's rule. `due_date`/`issue_date` are the alternatives. An advance
  invoice anchors on the first month it covers, so one document is never fined under two rules.
  The scheduler's edit/delete guard reads the SAME anchor (`FineRuleAnchor::column()`), so what
  a period locks and what it charges can never disagree. The fine
  accrues daily while principal is outstanding and **freezes** once principal is settled. A
  lease with **no fine rule accrues no fine** (no system-wide default until the council states
  one).
- **Fine periods**: a `FineRule` owns a window `[effective_from, effective_to]`, both ends
  inclusive; a null end means "and onwards". `FineRuleScheduler` is the ONE place periods are
  written and holds the invariant that **periods on a lease never overlap** — so "which rule
  fines this invoice" always has exactly one answer. A **gap** between periods is legitimate
  and means no fine accrues (the council's fine-holiday case). Adding a period that starts
  inside an open-ended predecessor **supersedes** it (closes it the day before); overlapping a
  *closed* period is refused, naming the conflict.
  **Edit/delete are gated on invoices, NOT on whether the period has started**:
  `dependentInvoiceCount()` counts invoices already fined by it (`fine_rule_id`) *plus* any
  raised inside its window that it has simply not fined yet (an invoice not past due has no
  fine computed, but this period is what will compute it) — so a period that ran over a quiet
  stretch is freely editable, while one covering a single invoice locks. Dependency uses the
  **effective** window from `effectiveWindow()` (clamped by the next period's start), so a
  legacy open-ended row is not blamed for invoices a later row took over. Once locked, the only
  mutation is closing an open end (`close()`); `update()` and `remove()` throw with the count. `invoices.fine_rule_id` records which
  period produced the current fine (rewritten on every refresh — a record of what happened, not
  a pin), and the same identity rides in the fine line item's `meta`. **Legacy rows all left
  the end open**, so a lease can carry several; resolution has always been "latest start wins"
  and `timeline()` renders them clamped to match, rather than claiming coverage that never
  applied. The manager lives in a wide modal on `/leases` (coverage strip incl. gaps → add-a-
  period form with presets, live conflict inspection via `inspect()` and a worked example
  priced by the real calculator → "Applied to" history).
- **Payments**: allocation is rent(principal incl. CSR)-first then fine, via
  `config/billing.php` (`fine_first` also implemented). The fine is recomputed **as of the
  actual payment date** before allocating. Overpayments are rejected (no credit balances).
- **Receipts are their own record** (`Receipt`, append-only like `Payment`). ONE handover of
  money = ONE `YYYY/NNN` number, even when it settles several invoices; the ledger still keeps
  **one payment row per invoice** (that invariant carries statements, the fine freeze and
  per-invoice reversal). `payments.receipt_number` is gone — the number lives on the receipt
  and `Payment::receipt_number` is an accessor reading through it, so call sites are unchanged.
  Reversal rows have `receipt_id` null and keep their own date/method. Bulk collection is
  `PaymentRecorder::recordForTenant()`: oldest-due-first, fines refreshed per invoice as of the
  payment date, overpay rejected across the selected set. Reversing one slice leaves the rest
  of the receipt intact.
- **Invoice/receipt numbering**: `YYYY/NNN` from locked per-year counter tables
  (`invoice_sequences`, `receipt_sequences`) — separate sequences, never reused. NOTE: the two
  formats look identical — always label which document type a number refers to.
- **Advance billing**: one invoice may span N months (`invoices.period_months`,
  `InvoiceGenerator::generateRange()`; CSR billed once per occurrence in the range). The monthly
  run is range-aware — months covered by a spanning invoice are skipped (overlap check via
  `period_start`/`period_end`, not just the unique first-month key). Use
  `Invoice::periodLabel()` for display ("Jan – Jun 2026"). An advance invoice books entirely
  into its first month's "billed" reporting figure.
- **Due-date anchoring**: `DueDateCalculator` is the ONE place an invoice due date is computed
  — the generator, the advance-billing preview and the lease-form hint all call it, so a
  preview can never promise a date billing won't honour. The rule is config
  (`billing.due_date_anchor`, env `BILLING_DUE_DATE_ANCHOR`, `DueDateAnchor` enum): the council
  rule in force is `start_day_based` — rent anchored ON the 1st (grace included, via
  `effectiveRentStart()`) is due the lease's `due_day` of the billed month; anchored mid-month
  it is due `due_day` of the NEXT month. `same_month`/`next_month` are the fixed alternatives;
  malformed config falls back to `start_day_based`. `due_day` is clamped to the landing month's
  length (30 → 28 Feb). Changing the config affects invoices generated from then on —
  already-issued invoices keep their recorded due date (money never reinterprets itself).
- **Separate CSR invoicing / invoice kinds**: `invoices.kind` (`InvoiceKind`: `rent` | `csr`)
  says what a document demands, and **whether late payment fines is a property of the kind**
  (`finable()`, checked at the top of `InvoiceFineApplier::previewFine()`) — a CSR invoice
  still goes Overdue and gets chased by reminders but NEVER grows a fine, as a stated rule,
  not an accident of its zero rent base. Which way a lease bills CSR is an agreement term:
  `leases.csr_billing` (`CsrBilling`: `with_rent` default | `separate`). A `separate` lease
  keeps CSR lines off ALL rent documents (monthly + advance ranges + previews) and instead
  gets one annual `csr`-kind invoice via `InvoiceGenerator::generateCsr($lease, $year)` —
  raised automatically by the monthly job when the CSR month comes round, or manually from
  the New-invoice modal ("Annual CSR" toggle, only shown for separate leases). Idempotency
  rides `period_key`: CSR keys are `{lease}-{year}-csr` (one live CSR document per year;
  voiding frees the year), rent keys keep their `{lease}-{year}-{month}` shape.
  `overlapping()` considers only `rent`-kind rows, so a CSR invoice and that month's rent
  invoice never block each other. Consequence to know: on a `separate` lease a
  `rent_plus_charges` fine base computes on rent alone — the charge moved off that document.
  The PDF drops the "late payment attracts a fine" footnote for non-finable kinds.
- **Invoice cancellation** (`App\Services\Billing\InvoiceCanceller`): an invoice raised in
  error is **voided**, keeping its row and its `YYYY/NNN` number — a hole in a government
  numbering sequence is unexplainable to an auditor. Allowed only while payments net to **zero**
  (so a recorded-then-reversed payment does NOT block it), reason mandatory, re-checked under
  `lockForUpdate()`. Every balance/statement/reminder/report already filters on status, so a
  Cancelled invoice simply stops being money; `outstandingTotalLaari()` also returns 0 outright
  and `refreshPaymentStatus()` refuses to resurrect it. **The month is freed**: the
  one-live-invoice-per-lease-per-month rule moved from a composite unique to a nullable-unique
  `invoices.period_key` (NULL when cancelled — the same portable trick as approvals'
  `pending_key`), derived in a model `saving` hook so it can never be forgotten, and
  `overlapping()` ignores cancelled rows. NOTE for MySQL: the composite unique was the only
  index supporting the `lease_id` FK, so the migration adds `invoices_lease_id_index` **before**
  dropping it. Gated on `IssueInvoices` (`InvoicePolicy::cancel`) — tighten to supervisor-only
  or route through approvals if the council asks. The PDF prints a "Cancelled — not payable"
  notice. **Adding an InvoiceStatus case breaks exhaustive `match` blocks in blades** — four
  status→lozenge maps exist (invoices/leases/tenants/portal).
- **Arrears follow-ups (R2)** (`App\Services\Collections\ArrearsFollowUpService`, `/follow-ups`):
  the chasing worklist. An `ArrearsContact` records what was said and what was promised
  (`promised_on` + optional `promised_amount_laari`, plus an `outstanding_at_contact_laari`
  snapshot so old history still reads right). **Whether a promise was KEPT is never stored** —
  `outcomeFor()` derives it from net payments since the contact date, so it cannot go stale and
  nobody can tick off money they did not collect; reversals are negative rows, so a bounced
  cheque un-keeps a promise on its own. `FollowUpState` (Broken → NeverContacted → Stale →
  Recent → Promised) IS the queue order — `priority()`/`needsAttention()` live on the enum. An
  open promise parks a tenant off the worklist until their date, then returns them at the top
  as Broken. `summary()` gives the council the promised-vs-unsecured split; the nav badge is
  `needsAttentionCount()`. Stale window is config (`collections.follow_up_stale_days`, 14).
  Gated on `record payments` — collectors, not readers (Reports' arrears page stays
  `view reports`).
- **Bank-transfer claims (T3)** (`App\Services\Portal\TransferClaimService`): a tenant who
  paid off-island submits amount + date + bank reference from the portal's "Pay by transfer"
  tab (`transfer_claims`, one pending per tenant); Finance/Supervisor confirm or reject it from
  `/transfers` (gated on `record payments`, badge in the sidebar like Approvals). A claim is a
  workflow record, NOT money — `confirm()` records a real bank-transfer payment through
  `recordForTenant()` dated to the stated transfer date (so it inherits oldest-first
  allocation, the no-credit guard, the append-only receipt AND the T1 confirmation SMS), then
  links the claim to that receipt. Over-claim → `InvalidPaymentException` surfaces as a claim
  error and the claim stays pending. Reject needs a reason, shown to the tenant.
- **Tenant portal (T2)** (`/portal`, Livewire pages in `app/Livewire/Portal`, layout
  `components.layouts.portal`): read-only self-service on its OWN auth guard — `tenant`
  (session driver, `Tenant` model, which is Authenticatable with NO password/remember columns;
  sign-in is an SMS one-time code via `App\Services\Portal\PortalOtpService`). OTP posture:
  hashed codes, 5-min TTL, 5 attempts, 3 codes/mobile/hour, identical response for unknown
  mobiles (no tenant enumeration), code MASKED in `notification_logs` (kind `portal_otp`),
  opt-out deliberately ignored (transactional). One mobile → several tenant records is real
  (legacy import): a chooser follows the OTP; only records behind the verified mobile may be
  picked. Guests on `portal*` redirect to `portal.login` (bootstrap/app.php). Portal PDFs are
  separate routes (`PortalPdfController`) gated on OWNERSHIP under the tenant guard — 404 not
  403 for others' records; staff PDF routes stay staff-gated. The ledger is
  `Reporting\TenantLedger`, shared with the staff statement so both always show one truth.
- **Tenant-facing SMS (T1)**: a `payment_confirmation` text fires from `PaymentRecorder`
  AFTER the transaction commits (never from inside it) — one per receipt, quoting the receipt
  number and the tenant's remaining balance; `balance_statement` goes monthly to tenants in
  arrears, idempotent per calendar month via `notification_logs`. Both live in `reminder_rules`
  (editable/toggleable on Settings → Reminders) but are NOT due-date-driven — the reminder
  dispatcher skips them via `ReminderKind::isDueDateDriven()`. Templates render through
  `TemplateRenderer::renderReceipt()/renderTenant()` (separate merge-field sets).
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
- **Lease workspace is a PAGE, the slide-over is a peek** (`/leases/{id}`,
  `App\Livewire\Leases\Show`): a lease is the richest entity here and needs a URL — one that
  survives a browser tab during a phone call and can be linked to. Layout is the standard
  detail-page shape: identity header + actions → **money band** ("Due today", green when
  settled) → main column (outstanding invoices with per-row Pay, invoice history with
  "Show more", payments & receipts) → reference rail (terms, fine rule, R2 collection state,
  tenant, activity). Invoices and payments are STACKED, never tabbed — reconciling a dispute
  needs both visible at once. The list peek keeps row-click and carries an "Open lease" link;
  tasks that still live on the list (edit form, fine schedule) are deep-linked back via
  `?edit=` / `?fines=` handled in `Leases\Index::mount()`, and the New-invoice modal via
  `?createFor=`.
- **`LeaseAccountSummary`** (`app/Services/Reporting`) is the ONE place "what is owed right
  now" is computed: it recomputes each fine live through `InvoiceFineApplier` rather than
  reading `invoices.fine_laari`, which is only as fresh as the last nightly refresh. Staff
  quote this on the phone, so it must equal what a payment taken today would settle.
  `forTenant()` sums the same engine across a tenant's leases — use it for any tenant balance
  shown ALONGSIDE a lease figure, or the rail ends up displaying a tenant-wide balance smaller
  than the single lease inside it (a test pins that invariant). `Tenant::outstandingBalance()`
  keeps its stored-fine semantics for SMS/portal/statements.
- **Record payment is one shared surface**: `resources/views/livewire/partials/payment-modal.blade.php`,
  backed by `InteractsWithPayments`, included by both the leases list and the lease page with
  an `$unpaidChoices` map. Don't fork it.
- **Slide-overs (560px) are the detail/action surface** — three exist; follow their pattern
  (backdrop + Esc via a `closeOverlays()` that unwinds overlays top-first):
  - **Leases**: row click → amount-due banner, field grid, fine-rule card (show the maths),
    recent invoices, activity timeline, permission-gated action bar (Record payment / Send
    reminder / Create invoice / Fine rule / Terminate / Edit).
  - **Invoices**: Record-payment slide-over — due summary, form, live rent/fine allocation for
    the chosen payment date, payments list with receipt/reverse actions.
  - **Tenants**: detail slide-over with consolidated balance (FR-TEN-05), contacts, message
    history, and an accordion drill-down lease → invoices → payments.
- **Row actions are icon buttons** (`icon-btn` + inline SVG) with `title` tooltips AND
  `aria-label`s — never icon-only without both. The advance-billing "New invoice" modal
  pre-suggests the lease's next unbilled month and quick-picks 1/3/6/12 months or
  "until lease end", with a live preview that surfaces range conflicts before submit.
- **Money display**: right-aligned, `tabular-nums`, formatted by `Money::format()`.
  **Status**: always a lozenge with a text label, mapped per UI_DESIGN_PRD §3.1 — never
  colour alone. One primary (blue) button per view.
- **Nav/actions are permission-gated** with `@can` AND server-side authorization. The §6.1
  matrix means e.g. Admin sees Invoices but no Record-payment; Finance can't open /leases.

## Project structure

- Services: `app/Services/Billing/` (InvoiceGenerator, InvoiceNumberGenerator, FineCalculator,
  FineBreakdown, InvoiceFineApplier, InvoiceCanceller, FineRuleScheduler, FineRuleResolver,
  DueDateCalculator, PaymentRecorder, ReceiptNumberGenerator), `Reminders/`,
  `Reporting/ReportService`, `Approvals/ApprovalService`,
  `Collections/ArrearsFollowUpService`, `Import/`, `Sms/`.
- Livewire pages: `app/Livewire/{Dashboard,Leases(Index+Show),Invoices,Tenants,Properties,Reports,Approvals,FollowUps,Settings}`.
  Shared form logic in `app/Livewire/Concerns/InteractsWithPayments` and
  `CollectsTenantPayments` (the latter is a trait, not a page, because §6.1 keeps a Finance
  Officer *out of* `/tenants` — they reach bulk collection from the Invoices payment
  slide-over's "also outstanding" banner; a Supervisor reaches it from the tenant slide-over). `Settings/Profile` is the
  self-service account page (`/settings/profile`, linked from the top-bar user chip, auth-only —
  no role gate; it manages only the signed-in user's own account).
- Jobs: `GenerateInvoices`, `SendPaymentReminders`. Commands: `leases:expire`,
  `invoices:generate`, `invoices:refresh-fines`, `import:register`.
- Schedule (routes/console.php): 00:05 expire · monthly 1st 00:10 invoices · 00:15 fines ·
  09:00 reminders · monthly 1st 09:05 balance statements (`tenants:send-balance-statements`).
- Enums in `app/Enums/` (backed, with `label()`); policies per model in `app/Policies/`;
  migrations use `bigInteger` laari money columns.
- PDFs: `resources/views/pdf/*` (self-contained inline CSS, no Vite). Each template embeds
  the council lockup and **Noto Sans Thaana** as base64 (`pdf/partials/fonts.blade.php`) so a
  document renders identically on any driver — hosts other than macOS ship no Thaana font and
  would print tenant names as tofu boxes. Keep the `unicode-range` (it confines the face to
  Thaana so Latin text is untouched); a test asserts every template embeds it.

## Commands

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # seeds roles, reminder rules, demo accounts + demo registry
npm run dev                     # or: npm run build
php artisan serve
php artisan queue:work
php artisan schedule:work
./vendor/bin/pest               # test suite (~260 tests) — must stay green
./vendor/bin/pint               # format — run before finishing any task

# Operations
php artisan invoices:generate --period=2026-07
php artisan invoices:refresh-fines --as-of=2026-07-10
php artisan import:register storage/app/import/register.xlsx --dry-run
```

**Tenant portal OTP testing bypass** (`config/portal.php`, `PORTAL_OTP_BYPASS_CODE`): set a
6-digit code to skip the SMS and let that one code sign in ANY tenant — QA convenience only.
Double-guarded: refused outright when `APP_ENV=production` (regardless of the value) and must be
a valid 6-digit code; a "Testing mode" banner shows it on the portal login. `phpunit.xml` pins
it empty so it never leaks from a local `.env` into tests (bypass tests enable it per-case).

Demo sign-ins (password `password`): admin@ / supervisor@ / land@ / finance@ / auditor@
example.com — each sees only what §6.1 allows; supervisor has the widest UI.

## Deferred backlog (flag these when relevant; do not silently re-scope)

- Fine waivers + FR-RPT-06 fine report (accrued/collected/waived). The approvals workflow is
  built and waits for it: add `ApprovalAction::WaiveFine` + an `ApprovalService::execute()`
  branch and Finance's `waive fines` A-cell routes itself.
- Notifying supervisors that a request is waiting (currently the nav badge only — no email
  channel exists yet, and SMS is tenant-facing).
- Historical ledger import (old invoices/receipts; receipt-sequence continuation = PRD §14.4.37).
- Email channel (INT-EML-01); MsgOwl delivery-status callbacks.
- Proration (FR-INV-08); admin-configurable usage types (FR-PRP-04); per-lease/tenant reminder
  overrides (FR-NOT-04).
- Spreading an advance invoice's "billed" figure across its covered months in reports
  (currently books into the first month).

## Working agreement

- Work in reviewable increments; confirm a short plan before large changes and stop for review after.
- Write/adjust Pest tests for any money logic FIRST; keep the suite green; run `pint` at the end.
- If a requirement is ambiguous, check the PRD (+ its Appendix B); if still unclear, ask rather
  than guessing — except where a default is listed above.
- Nothing here has been committed to git without an explicit request from the user.
