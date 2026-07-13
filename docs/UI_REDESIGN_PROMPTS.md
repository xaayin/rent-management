# UI Redesign Prompt Pack — Council Land & Property Lease Management

Copy-paste prompts for redesigning every screen of this application in a new design system
using Claude Code. **Workflow:** start each session by pasting the **Master Context Prompt**
(Part A) once, filling in the design-system placeholder — then paste one **page prompt**
(Part B) at a time, review the result, and continue.

---

## Part A — Master Context Prompt (paste first, once per session)

```
You are restyling an existing, fully working Laravel 13 + Livewire v4 + Tailwind CSS v4
application to a new design system. Read CLAUDE.md fully first.

THE NEW DESIGN SYSTEM
Source of truth: <FILL IN — e.g. path to your design-system CSS/tokens, a component library,
a skill name, or a reference file such as design/new-system.html>
Use ONLY this design system's tokens, components and patterns for all visual styling.

THE APPLICATION
A council back-office for managing land/property leases: registry (properties, tenants,
leases), automatic monthly invoicing with per-lease late-fine rules, payment recording with
receipts, SMS reminders, dashboards/reports, and a legacy-register importer. Users are five
staff roles with strict RBAC (PRD §6.1): administrator, finance_officer, land_officer,
supervisor, auditor. Demo sign-ins: admin@/finance@/land@/supervisor@/auditor@example.com,
password "password".

NON-NEGOTIABLE FUNCTIONAL GUARDRAILS (restyle presentation ONLY)
1. Do not change any Livewire wiring: wire:model / wire:click / wire:submit / wire:keydown
   attributes, component class methods, property names, routes, or policies.
2. Keep every @can / permission gate exactly where it is — visibility rules are law (§6.1).
3. Money: always right-aligned, tabular numerals, rendered via Money::format() ("MVR 1,060.00").
   Never reformat amounts in Blade.
4. Statuses: always a colour + TEXT label element (never colour alone). Current mapping:
   lease Active=success, Draft=neutral, Terminated=danger, Expired=neutral, "Overdue Nd"=danger,
   "Expires Nd"=warning; invoice Paid=success, Partly paid=warning, Overdue=danger, Issued=info;
   tenant Organisation=discovery/purple, Individual=info/blue. Map these to the new system's
   equivalent status component.
5. Accessibility: focus-visible rings on all interactive elements, aria-label + tooltip on
   icon-only buttons, role="dialog"/aria-modal on overlays, Esc + backdrop-click close,
   prefers-reduced-motion respected.
6. One primary action button per view; everything else secondary/subtle.
7. The Pest suite (~262 tests) MUST stay green: ./vendor/bin/pest. Some tests assert visible
   strings (e.g. "Showing 1–10 of 12", "Add a lease", "Amount due", "Record payment",
   "Recent invoices", flash messages like "Payment recorded · receipt 2026/001 issued").
   Keep this copy verbatim, or update the corresponding test in the same change.
8. Run ./vendor/bin/pint and npm run build at the end of every task.

SHARED UI INFRASTRUCTURE TO MIGRATE FIRST (before any page)
- resources/css/app.css — currently holds the old tokens (@theme) and component classes
  (@layer components: btn-primary, btn-subtle, btn-danger, icon-btn, nav-item, loz + 6
  semantic variants, ava, chip, tab, th/td, fl/fl-req/fv, input, menu-row, stat, pg) plus
  motion keyframes (slideover-enter, overlay-enter, toast-auto). Replace the internals of
  these classes with the new design system's styles — keeping the SAME class names means most
  Blade files restyle automatically; then refine page by page.
- resources/views/components/modal.blade.php — centred dialog (default 480px / wide 760px),
  header + close, slot for body/footer.
- resources/views/components/pagination.blade.php — "Showing X–Y of Z" + windowed page buttons.
- resources/views/components/toast.blade.php — flash confirmation pill, CSS auto-dismiss,
  rendered INSIDE each Livewire root.
- resources/views/components/layouts/app.blade.php — the app shell (see Page 0 prompt).
- resources/views/components/layouts/guest.blade.php — centred auth card layout.

GLOBAL PAGE PATTERNS (recreate these in the new system, keep behaviour identical)
- List pages: breadcrumb → page header (title + one primary button) → toolbar (debounced
  search input + select-based filter chips + "Clear filters" + right-aligned count) →
  optional tabs → data table in a card (outer clip + inner horizontal scroll) → pagination
  footer. Filters live in the URL; changing any filter resets to page 1.
- Detail/action surfaces are right slide-overs (max 560px) with a header, optional action
  bar, scrollable body, sometimes a footer. Esc unwinds overlays top-first.
- Create/edit forms are modals. The top-bar Create menu deep-links with ?create=1.
- Empty states are filter-aware ("No X match this view — clear the filters." vs
  "No X yet — create the first one.").
```

---

## Part B — Page prompts

### Page 0 · App shell (layout, nav, Create menu)

```
Restyle the app shell in resources/views/components/layouts/app.blade.php using the new
design system.

Anatomy (keep all links, gates and counts):
- Fixed top bar (56px): product mark + name (link to /dashboard); centred global search form
  (GET to leases.index, input name="q", visible only @can manage leases); right cluster:
  Create split-menu (a <details> dropdown — items New lease / New tenant / New property, each
  permission-gated, each deep-linking with ?create=1, each with icon + title + one-line
  description), the user chip (name + initials avatar, links to /settings/profile,
  highlighted when active), and a Sign out button (POST /logout form).
- Fixed left sidebar (240px, hidden below md): section label; nav items with 18px line icons
  — Dashboard, Leases (+count pill of total leases), Tenants, Properties, divider, Invoices
  (+danger count pill of overdue invoices, only when > 0), Reports, divider + "Settings"
  label, Users & roles, Reminders & SMS. Every item permission-gated; active item uses the
  selected style driven by request()->routeIs(...).
- Sidebar footer: workspace card ("Malé City Council / Revenue Section").
- Main content column: max-width 1200px, centred, padded.

States: active nav item, hover, focus-visible. The Create menu must work without JS
frameworks (native <details>). Verify by signing in as each demo role — the nav must show
exactly what §6.1 allows.
```

### Page 0b · Auth screens (login + two-factor challenge)

```
Restyle resources/views/auth/login.blade.php, resources/views/auth/two-factor-challenge.blade.php
and their wrapper resources/views/components/layouts/guest.blade.php.

Login: centred brand mark + product name; card with title "Sign in", helper line "Council
staff access only.", error summary block ($errors->first()), email + password fields,
"Remember me" checkbox, full-width primary submit. Form POSTs to route('login') — keep field
names email/password/remember.

Two-factor challenge: same card idiom; title "Two-factor authentication"; 6-digit code input
(inputmode=numeric, autocomplete=one-time-code, centred wide-tracking digits) posting `code`
to /two-factor-challenge; collapsed secondary section "Lost your device? Use a recovery code"
revealing a `recovery_code` form posting to the same URL. Keep both forms and field names.
```

### Page 1 · Dashboard (/dashboard)

```
Restyle the dashboard: app/Livewire/Dashboard/Index.php renders
resources/views/livewire/dashboard/index.blade.php. All five roles can view it.

Anatomy:
- Breadcrumb "Council / Revenue"; H1 "Dashboard"; subtitle "Overview of leases, billing and
  arrears — {Month Year}"; header actions: "Reports" secondary link (@can view reports) and
  "Create lease" primary link (@can create lease, deep-links ?create=1).
- Five stat cards (2-col mobile / 5-col desktop): Active leases; Billed this month (+note
  "N invoices issued"); Collected this month (+note "X% of billed" or "—"); Arrears
  (emphasised/danger treatment on the card, red value, note "N overdue invoices"); Fines
  outstanding (note "accruing daily"). Values come pre-formatted from $metrics.
- Two-thirds card "Needs attention · overdue": header row with "View all arrears" link; up to
  5 rows — property name + agreement · tenant (two-line cell), "Overdue Nd" danger status,
  right-aligned amount, chevron link to the leases list filtered by agreement. Empty state:
  "No overdue invoices — everything's up to date."
- One-third card "Upcoming expiries": icon-chip list items (warning icon when ≤30 days to
  expiry, info otherwise) with property name + label line ("Expires in N days · renewal due"
  / "Grace period ends in N days"). Empty state: "Nothing expiring in the next 90 days."
```

### Page 2 · Leases list + workspace (/leases)

```
Restyle the leases workspace: app/Livewire/Leases/Index.php +
resources/views/livewire/leases/index.blade.php. Access: manage-leases roles (land officer,
supervisor). This is the app's richest screen — FOUR layers share the page. Keep every
wire binding and @can gate.

Layer 1 — list page:
- Breadcrumb "Council / Leases"; H1; "Create lease" primary button (icon + label).
- Toolbar: search input (wire:model.live.debounce.300ms="q", placeholder "Filter leases");
  three select filter-chips (statusFilter: Draft/Active/Terminated/Expired · tenantTypeFilter
  · propertyTypeFilter) that show an active/selected treatment when set; "Clear filters"
  appears when anything is set; right-aligned "N leases" count.
- Saved-view tabs: All / Active / Overdue / Expiring soon (wire:click="$set('tab', …)").
- Table columns: Property (name + "AG-… · N ft²" second line) · Tenant (initials avatar +
  name) · Type (Organisation/Individual status label) · Status (smart label: "Overdue Nd"
  danger, "Expires Nd" warning, else lease status) · Monthly rent (right) · Next due · chevron.
  Row click = selectLease; selected row highlighted. Pagination footer (<x-pagination>).

Layer 2 — detail slide-over (renders when $detail !== null, 560px right panel):
- Header: agreement no · land no eyebrow, property name title, close icon-button.
- Action bar on muted band: Record payment (primary, @can create Payment, when unpaid
  invoices exist) · Send reminder · Create invoice link (@can issue invoices) · right side:
  Fine rule (@can configureFineRule) · Terminate (danger-tinted, active leases,
  @can terminate) · Edit (@can update).
- Body: amount-due banner (danger when overdue days > 0 with "Overdue N days" label, warning
  "Awaiting payment", or success "All settled"); inline terminate-confirm section (reason
  input + Confirm termination danger button) when terminatingId matches; two-column field
  grid (Status, Tenant, Tenant type + registry, Property + size, Rent basis with the maths,
  Due day, Lease start, Expiry, optional Grace/CSR/Termination rows); fine-rule card (header
  + method status label; rows Method/Base/Allowance/Cap/Effective-from; accrued-fine total
  row in danger when overdue — "show the maths" is a core design principle); inline
  fine-rule form (when fineRuleLeaseId matches — method select drives conditional fields,
  Save/Cancel); "Recent invoices" mini-table (period label + number, total, status label,
  PDF link @can view reports); "Activity" timeline (avatar, actor, description, timestamp).
- Empty states: "No invoices yet for this lease." / "No recorded activity."

Layer 3 — record-payment modal (renders when $paying !== null AND $detail !== null, sits
above the slide-over): invoice select (unpaid invoices of this lease), amount / date /
method / reference fields, live breakdown card (rent & charges outstanding, fine as of
payment date, total due; allocation split of the entered amount with over-payment warning;
footnote "Allocation: rent first, then fine…"), footer Cancel + primary "Record payment".

Layer 4 — create/edit modal (wide 760px, when $showForm): full lease form — agreement no,
status, property/tenant selects, dates (expiry auto-computed), rent basis select driving
per-ft² (rate + area) vs flat fields, and a grouped "Charge configuration" section (grace,
due day, CSR type driving conditional fields). Footer Cancel + "Create lease"/"Save changes".

Esc unwinds: payment modal → form modal → slide-over (wire:keydown.escape.window on root).
```

### Page 3 · Invoices (/invoices)

```
Restyle app/Livewire/Invoices/Index.php + resources/views/livewire/invoices/index.blade.php.
Access: issue-invoices roles (admin, finance, supervisor); some actions gated further.

Header: breadcrumb "Billing / Invoices"; H1 + subtitle; right cluster: "Billing month"
month-input + "Generate this month" secondary button (wire:click="generate", with
wire:loading state "Generating…") + "New invoice" PRIMARY button (wire:click="openCreateInvoice").

Toolbar: search ("Filter invoices"); filter chips statusFilter (Issued/Partly paid/Paid/
Overdue), tenantTypeFilter, propertyTypeFilter, plus a month-input periodFilter; Clear
filters; "N invoices" count.

Table: Number · Tenant · Property · Period (periodLabel(), plus an "N mo" info badge when
period_months > 1) · Due · Rent (right) · Charges (right) · Fine (right, danger-toned when
> 0) · Total (right, emphasised) · Status label · Actions. Actions are ICON buttons with
tooltip + aria-label: Invoice PDF (document icon, @can view reports), Send reminder (bell,
unpaid only), Record payment (card icon, accent colour, unpaid only, @can create Payment).
Pagination footer; filter-aware empty state.

Record-payment slide-over (renders when $paying !== null, 560px right panel): header
(invoice number · period eyebrow, "Record payment" title, tenant · property line, close);
body — due summary card (rent & charges outstanding / fine as of payment date / total due),
form grid (amount, date, method, reference), live allocation card (splits + over-payment
warning + rent-first footnote), "Payments on this invoice" list (receipt no + Reversed badge
+ date/method/allocation meta, amount, icon actions Receipt PDF & Reverse (danger); inline
reversal-reason confirm row with Confirm reversal danger button); footer Cancel + primary
"Record payment".

New-invoice modal (when $creatingInvoice — advance billing): lease select ("AG — tenant ·
property"); First billing month (month input, pre-suggested) + Months covered (number);
quick-pick chips 1 month / 3 months / 6 months / 1 year (active state when inv_months
matches) + "Until lease end"; live preview card (Period covered + months, Rent = N ×
monthly, CSR × occurrences, Invoice total, "Due {date} · one invoice, one payment.",
range-conflict error in danger which also disables the primary button); footer Cancel +
"Create invoice".

Esc unwinds: new-invoice modal → payment slide-over (closeOverlays).
```

### Page 4 · Tenants (/tenants)

```
Restyle app/Livewire/Tenants/Index.php + resources/views/livewire/tenants/index.blade.php.
Access: manage-tenants roles.

List: breadcrumb "Registry / Tenants"; H1 + "New tenant" primary; toolbar (search "Filter
tenants", typeFilter chip, Clear filters, count); table: Name (avatar + name) · Type label ·
Registry no. · Mobile · Leases count (right) · chevron. Row click = selectTenant.
Pagination + filter-aware empty state.

Detail slide-over (when $detail !== null) — a THREE-LEVEL drill-down:
- Header: large initials avatar, type label + registry number eyebrow, tenant name, close.
- Action bar: "Statement" primary link (@can view reports) · "Edit" (opens the edit modal
  ON TOP of the slide-over — keep that stacking).
- Consolidated balance banner: danger "Balance due · all leases" + amount + "Outstanding"
  label, or success "MVR 0.00" + "All settled".
- Contact grid: Mobile, Email, SMS reminders status label (Enabled success / Opted out
  warning / No mobile warning), optional Contact person, Postal address.
- "Leases (N)" accordion list — level 1 rows (chevron that rotates when expanded, property
  name + "agreement · rent/mo" meta, lease status label, right-aligned per-lease outstanding
  in danger when owed) → expanding loads level 2 invoice rows (smaller chevron, number +
  period label, invoice status label, total, Invoice-PDF icon with wire:click.stop) →
  expanding an invoice loads level 3 payment rows (receipt no or "Reversal of …" in danger
  with reason, date/method/allocation meta, amount, Receipt-PDF icon). Preserve the
  indentation hierarchy so levels read clearly.
- "Recent messages": last 5 SMS logs — Sent/Failed status label, truncated message text,
  timestamp · recipient.

Create/edit modal (when $showForm): type select (drives National ID vs Company reg no +
Contact person fields), name, mobile, email, postal address, "Opted out of SMS reminders"
checkbox. Footer Cancel + Create tenant/Save changes.

Esc unwinds: form modal → slide-over.
```

### Page 5 · Properties (/properties)

```
Restyle app/Livewire/Properties/Index.php + resources/views/livewire/properties/index.blade.php.
Access: manage-properties roles.

List: breadcrumb "Registry / Properties"; H1 + "New property" primary; toolbar (search
"Filter properties", usageFilter + statusFilter chips, Clear filters, "N properties" count);
table: Name · Land no. · Usage · Size ft² (right) · Status label (Active success / Archived
neutral) · actions (Edit, Archive/Restore — currently text buttons; convert to icon buttons
with tooltips consistent with the Invoices page). Pagination + filter-aware empty state.

Create/edit modal: name, land/parcel number, size, usage type select, location notes.
Footer Cancel + Create property/Save changes. Esc closes (root handler calls cancel).

Note: imported land numbers may contain Thaana text and synthesised suffixes — the Land no.
column must handle long RTL-ish strings gracefully (truncate with title tooltip).
```

### Page 6 · Tenant statement (/tenants/{id}/statement)

```
Restyle app/Livewire/Tenants/Statement.php + resources/views/livewire/tenants/statement.blade.php.
Access: view-reports (all five roles). A printable-feeling ledger page.

Header: breadcrumb "Registry / Tenants / Statement"; H1 "Account statement"; meta line
(tenant name · registry no · "consolidated across N lease(s)"); right-aligned "Balance due"
summary card (danger amount when positive, success when zero).

Ledger table: Date · Description (bold label + muted detail line — invoices show
"Invoice N — property, period" with rent/CSR/fine detail; payments show "Payment — receipt N
(method)" with allocation detail; reversals show reason) · Debit (right) · Credit (right,
success-toned) · running Balance (right, emphasised). Empty state: "No transactions yet for
this tenant."
```

### Page 7 · Reports — Arrears (/reports/arrears) and Income (/reports/income)

```
Restyle app/Livewire/Reports/{Arrears,Income}.php + their views under
resources/views/livewire/reports/. Access: view-reports (all roles).

Shared header: breadcrumb "Council / Reports"; H1 "Reports"; tab bar Arrears | Income
(link-tabs, active underline); header actions "Export CSV" + "Export PDF" secondary buttons
(Income also has a year select bound wire:model.live="year").

Arrears: summary strip ("N overdue invoices" left; "Outstanding {amount} · fines {amount}"
right, outstanding in danger); table Invoice · Tenant (avatar + name) · Property · Due date ·
Overdue ("Nd" danger label) · Current fine (right, danger when > 0) · Outstanding (right,
emphasised) · Remind button (@can issue invoices). Empty state: "No overdue invoices —
everything's up to date."

Income: two-thirds card "Billed vs collected — {year}" (Month rows, zero months muted,
bold Total row with heavier rule); one-third stacked cards "Collected by property type" and
"Collected by tenant type" (label/amount rows; tenant types shown with their status labels).
Empty states: "No collections in {year}."
```

### Page 8 · Settings — Users & roles (/settings/users)

```
Restyle app/Livewire/Settings/UserManagement.php +
resources/views/livewire/settings/user-management.blade.php. Access: administrators only.

- Breadcrumb "Settings / Users"; H1 "Users & roles" + helper line.
- "Add a staff user" card: name, email, temporary password, role select (labels from the
  Role enum). Consider converting to the standard create-modal pattern for consistency —
  if you do, keep method names (create/save semantics) and update tests accordingly.
- Users table: Name · Email · Role (inline select per row, wire:change="updateRole(id,
  $event.target.value)" — keep this binding exactly).
```

### Page 9 · Settings — Reminders & SMS (/settings/reminders)

```
Restyle app/Livewire/Settings/Reminders.php +
resources/views/livewire/settings/reminders.blade.php. Access: administrators only
(configure notifications).

- Breadcrumb; H1 "Reminders & SMS" + helper line.
- Info card: "SMS provider: {driver} driver" as an info label + explanation line + merge-field
  legend rendered from TemplateRenderer::FIELDS in code style.
- One card per reminder rule (Before the due date / On the due date / After the due date):
  header row = rule name + "Enabled" checkbox; days-offset number input (hidden for on-due;
  label says "before"/"after" accordingly); message-template textarea. All bound as
  rules.{id}.enabled / .days / .template — keep bindings.
- Single primary "Save reminder settings" at the end (wire:submit="save").
```

### Page 10 · Settings — My account (/settings/profile)

```
Restyle app/Livewire/Settings/Profile.php +
resources/views/livewire/settings/profile.blade.php. Access: every signed-in user.

Four stacked cards (max ~640px column):
1. Profile — name + email fields, "Save profile" primary.
2. Change password — current password, new + confirm grid, "Change password" primary.
3. Two-factor authentication — header carries a state label (Enabled success / "Confirm to
   finish" warning / Off neutral). Three states in the body:
   - disabled: explainer + "Recommended for your role" warning label when $privilegedRole +
     password-confirm field + "Enable two-factor" primary;
   - pending: QR code panel ({!! $qrCodeSvg !!} on a white surface) beside the manual secret
     key (code style) + 6-digit code input + "Confirm & enable" primary + Cancel;
   - enabled: recovery-codes panel (warning-toned, 2-col code grid, "store these safely"
     copy) with Show/Regenerate actions, and a separated danger zone "Disable two-factor"
     (password field + danger button).
4. Browser sessions — explainer + password field + "Sign out other sessions" secondary.

Keep every wire:model/wire:click name (twofa_password, twofa_code, enableTwoFactor,
confirmTwoFactor, cancelTwoFactorSetup, regenerateRecoveryCodes, disableTwoFactor,
logoutOtherSessions, sessions_password).
```

### Page 11 · Shared components polish pass

```
After all pages, do one consistency pass over the shared components in the new system:
- <x-modal> (resources/views/components/modal.blade.php): header/close, body slot, footer
  band; default vs wide width; backdrop treatment.
- <x-pagination> (components/pagination.blade.php): "Showing X–Y of Z", windowed numbered
  buttons with active state, disabled prev/next arrows, ellipses.
- <x-toast> (components/toast.blade.php): success confirmation pill, bottom-centre, CSS
  auto-dismiss animation (toast-auto keyframes) — must not require JS.
- Empty states across all tables: consistent icon/copy treatment.
- Focus rings, hover states, and reduced-motion behaviour everywhere.
Then run the full suite (./vendor/bin/pest), pint, and npm run build, and click through the
app as supervisor@example.com (widest UI) plus one restricted role (finance@) to verify the
permission-gated variants both look right.
```

### Page 12 · Print/PDF templates (optional, if the new system defines print styles)

```
Restyle the PDF templates in resources/views/pdf/ (invoice.blade.php, receipt.blade.php,
arrears-report.blade.php, income-report.blade.php). Constraints: fully self-contained inline
CSS (no Vite/app.css — they render in headless Chromium via spatie/laravel-pdf); A4; keep
every data element — especially the itemised fine breakdown on invoices (method, due date,
late days/months, per-tier amounts, cap) which is a legal/PRD requirement (FR-FIN-12) — and
the payment account footer. Verify with: php artisan tinker →
Pdf::view('pdf.invoice', ['invoice' => Invoice::with('lease.tenant','lease.property','lineItems')->first()])->save('/tmp/test.pdf')
```

---

## Suggested order of execution

1. Master prompt + shared infrastructure (Part A note: migrate app.css class internals first
   — most pages inherit the new look instantly because class names are stable).
2. Page 0 (shell) + 0b (auth) — the frame everything sits in.
3. Page 2 (Leases) — the richest screen; decisions made here set precedents.
4. Pages 3, 4, 5 (Invoices, Tenants, Properties) — reuse the precedents.
5. Pages 1, 6, 7 (Dashboard, Statement, Reports).
6. Pages 8, 9, 10 (Settings).
7. Page 11 consistency pass, then Page 12 PDFs if desired.

After each page: ./vendor/bin/pest && ./vendor/bin/pint && npm run build, then eyeball the
page as at least two roles.
```
