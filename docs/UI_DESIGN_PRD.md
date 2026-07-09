# UI Design PRD — Council Land & Property Lease Management System

**Companion to:** `PRD.md` (product requirements) · `BUILD_PLAN.md` (delivery) · `../CLAUDE.md` (engineering rules)
**Live reference:** `../design/ui-prototype.html` — an interactive Tailwind prototype of the shell and key screens.
**Version:** 1.0 (Draft for review)

> This document specifies the visual and interaction design for the system. It follows the **Atlassian
> Design System (ADS) idiom** used by Jira — persistent left navigation, a top bar with global search
> and a single **Create** action, status **lozenges**, dense list/table views, and slide-over detail
> panels — expressed as an **original token set implemented in Tailwind CSS**. It is a design in the ADS
> *style*, not a copy of Atlassian's proprietary brand assets or typeface.

---

## 1. Design goals

The interface serves council revenue and land officers doing repetitive, data-heavy work: finding a
lease, reading a balance, recording a payment, chasing arrears. The design optimises for that.

1. **Scannability over decoration.** Dense tables, clear status colour-coding, right-aligned money.
2. **One obvious action per screen.** A single primary (blue) button; everything else is subtle.
3. **Show the maths.** Fines and totals always display their breakdown (per PRD FR-FIN-12).
4. **Low training cost.** Familiar Jira-like patterns; consistent placement and vocabulary.
5. **Calm, professional, neutral.** A government tool — trustworthy, not flashy.
6. **Accessible by default.** WCAG 2.1 AA contrast, visible focus, full keyboard support.

## 2. Design principles (ADS-aligned)

- **Neutral canvas, blue for intent.** The UI is greys and ink; brand blue marks the primary action,
  selected state, and links — nothing else competes for it.
- **Lozenges carry status.** Every stateful record shows a compact, colour-coded lozenge so a list can
  be read at a glance.
- **8-point grid.** All spacing, sizes and rhythm derive from a 4/8px scale.
- **Elevation is subtle.** Cards sit on a faint two-part shadow; overlays (modals, slide-overs) lift
  higher. No heavy drop shadows.
- **Progressive disclosure.** Lists → row click opens a **slide-over** with detail; deep edits open a
  focused modal. The user keeps their place in the list.

---

## 3. Foundations (design tokens)

Tokens are the single source of truth. Section 9 shows how to load them into Tailwind (v4 and v3).

### 3.1 Colour

**Brand (blue).** Primary actions, links, selected state.

| Token | Hex | Use |
|---|---|---|
| `brand-50`  | `#E9F2FF` | selected nav/row background, subtle fills |
| `brand-100` | `#CCE0FF` | hover on subtle blue surfaces |
| `brand-300` | `#579DFF` | focus ring |
| `brand-500` | `#0C66E4` | **primary** buttons, links, active indicators |
| `brand-600` | `#0055CC` | primary hover |
| `brand-700` | `#09326C` | pressed / text on pale blue |

**Neutrals (ink & surfaces).**

| Token | Hex | Use |
|---|---|---|
| `ink`      | `#172B4D` | primary text |
| `subtle`   | `#44546F` | secondary text, icon default |
| `muted`    | `#626F86` | tertiary text, placeholder (AA on white) |
| `faint`    | `#8590A2` | disabled / hint only (decorative) |
| `line`     | `#DFE1E6` | default borders |
| `line-2`   | `#EBECF0` | dividers, subtle borders |
| `surface`  | `#FFFFFF` | cards, panels, rows |
| `sunken`   | `#F7F8F9` | page background, table header |
| `hover`    | `#F1F2F4` | row/control hover |
| `selected` | `#E9F2FF` | selected row/nav |

**Semantic (lozenges & feedback).** Each is a subtle background + bold foreground pair.

| Token | Background | Foreground | Meaning |
|---|---|---|---|
| `neutral`   | `#EBECF0` | `#44546F` | draft, default, no status |
| `success`   | `#DCFCE7` | `#216E4E` | active, paid |
| `warning`   | `#FFF7D6` | `#974F0C` | due today, expiring, partly paid |
| `danger`    | `#FFECEB` | `#AE2E24` | overdue, terminated, error |
| `info`      | `#E9F2FF` | `#0055CC` | individual tenant, informational |
| `discovery` | `#F3F0FF` | `#5E4DB2` | organisation tenant, special |

**Domain status → lozenge mapping** (use consistently everywhere):

| Entity | Value | Lozenge |
|---|---|---|
| Lease | Active | `success` · "Active" |
| Lease | Draft | `neutral` · "Draft" |
| Lease | Terminated | `danger` · "Terminated" |
| Lease | Expired / Expiring soon | `neutral` / `warning` |
| Invoice | Paid | `success` |
| Invoice | Partly paid | `warning` |
| Invoice | Overdue | `danger` · "Overdue Nd" |
| Invoice | Issued / pending | `info` |
| Tenant | Organisation | `discovery` |
| Tenant | Individual | `info` |

### 3.2 Typography

**Typeface:** **Inter** (open-source; the standard substitute for Atlassian's proprietary Charlie
Sans). Fallback stack: `Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.

| Role | Size / line | Weight | Use |
|---|---|---|---|
| Page title | 24 / 28 | 600 | one per page (H1) |
| Section title | 20 / 24 | 600 | slide-over title, panel heads |
| Subhead | 16 / 24 | 600 | modal titles, card heads |
| Body / table | 14 / 20 | 400–500 | default text, table cells |
| Secondary | 13 / 20 | 400 | supporting text, meta |
| Small | 12 / 16 | 400–500 | captions, pagination |
| Label (eyebrow) | 11 / 16 | 600, uppercase, tracked | field labels, breadcrumbs |

Numbers in tables and money use **tabular figures** (`tabular-nums`) and are **right-aligned**.

### 3.3 Spacing, radius, elevation

- **Spacing:** 8px base with 4px increments (`4, 8, 12, 16, 24, 32`). Control height **32px** (`h-8`);
  input/table-row height **36px** (`h-9`); top bar **56px**; sidebar width **240px**.
- **Radius:** `sm 3px` (lozenges), `DEFAULT 4px` (buttons, inputs), `md 6px` (cards, panels),
  `lg 8px` (modals).
- **Elevation:**
  - `card` — `0 1px 1px rgba(9,30,66,.25), 0 0 1px rgba(9,30,66,.31)` (resting cards/tables).
  - `overlay` — `0 8px 16px -4px rgba(9,30,66,.25), 0 0 1px rgba(9,30,66,.31)` (modals, slide-over,
    menus, toasts).
- **Focus ring:** `2px` `brand-300` with `1px` offset, on `:focus-visible` only.

### 3.4 Iconography

Line icons, 1.5–2px stroke, 16–20px, `currentColor` (inherits text colour). Lucide is a good match
for the ADS look and is available in the chosen stack (`lucide` / inline SVG). Icons are decorative
support for text labels, never the only signifier of meaning.

---

## 4. Layout & navigation

### 4.1 App shell

```
┌───────────────────────────────────────────────────────────────────────────┐
│ [K] Kuli · Lease Mgmt   [ 🔍 global search        ]   [+ Create] 🔔 ? (AR) │  top bar 56px
├──────────────┬────────────────────────────────────────────────────────────┤
│ Dashboard    │  Council / Leases                                           │
│ Leases    18 │  Leases                                    [Export] [+ ...]  │  page header
│ Tenants      │  ── filter bar ──────────────────────────────────────────   │
│ Properties   │  [All][Active][Overdue][Expiring]                           │  tabs
│ ── ───       │  ┌──────────────────────────────────────────────────────┐   │
│ Invoices   6 │  │  data table (issue-list style)                       │   │
│ Payments     │  └──────────────────────────────────────────────────────┘   │
│ Reports      │                                                             │
│ Settings     │                                             [slide-over ▸]  │
│ [Malé City]  │                                                             │
└──────────────┴────────────────────────────────────────────────────────────┘
```

- **Top bar (fixed, 56px):** product mark + name at left; centred **global search**; right cluster of
  **Create** (primary), notifications, help, and avatar. Mirrors Jira's global bar.
- **Left sidebar (fixed, 240px):** section nav grouped with a small uppercase label. Selected item uses
  `selected` background + `brand-600` text/icon. Counts (e.g. overdue invoices) appear as trailing
  pills. A workspace/section footer sits at the bottom. Collapses off-canvas below `md` with a
  hamburger toggle and backdrop.
- **Content (max ~1200px):** breadcrumb → page header (title + primary/secondary actions) → optional
  filter bar and tabs → content.

### 4.2 List → detail pattern

Row click opens a **right slide-over** (max 560px) with the record's summary, key fields, fine rule,
recent invoices, and an activity log — plus an action bar (**Record payment**, **Send reminder**,
**Edit**). This keeps the list in place, exactly like Jira's issue detail. Full-page edit is reserved
for long forms (create/amend lease).

### 4.3 Responsive

- **≥1024px:** full shell, multi-column dashboard.
- **768–1024px:** sidebar persists; grids collapse to fewer columns; tables scroll horizontally.
- **<768px:** sidebar becomes an off-canvas drawer; slide-over and modal go full-width; tables scroll.

---

## 5. Component library

Each component below is realised in the prototype and expressed as a Tailwind component class
(`@layer components`). States listed are required.

### 5.1 Buttons
- **Primary** (`btn-primary`): white on `brand-500`; hover `brand-600`; active `brand-700`. One per
  view. Height 32px, radius 4px.
- **Subtle** (`btn-subtle`): `subtle` text, transparent; hover `hover`. Secondary actions.
- **Icon** (`icon-btn`): 32px square, `muted` icon, hover `hover`. Overflow "⋯", close, etc.
- **Danger** (variant): white on `danger-fg` for destructive confirmation only.
- States: default, hover, active, focus-visible (ring), disabled (40% opacity, no pointer).

### 5.2 Lozenges (status badges)
`loz` + a semantic modifier. 20px tall, 3px radius, 11px bold uppercase tracked. Colour follows the
mapping in §3.1. Never rely on colour alone — the text label always states the status.

### 5.3 Inputs & forms
- Text/select/date (`input`): 36px, radius 4px, `line` border; focus → `brand-500` border + ring.
- Labels sit **above** the field; required labels use `fl-req`; helper/eyebrow labels use `fl`.
- **Inline validation:** error state uses `danger` border + a short message below in `danger-fg`.
  Errors say what to fix, in plain language ("Enter an amount greater than 0"), never a raw code.
- Money inputs are right-aligned, tabular, prefixed with "MVR".

### 5.4 Tables (issue-list)
- Header: `sunken` background, 11px uppercase `muted`, bottom `line` border, sticky on scroll.
- Rows: 44–48px, hover `hover`, bottom `line-2` divider, whole row is the click target to open the
  slide-over. Money columns right-aligned + tabular. A trailing "⋯" actions button stops propagation.
- Footer: result count + pagination (`pg`, active `pg-active`).
- **Empty state:** centred icon + one-line explanation + a primary action ("No overdue invoices —
  everything's up to date.").

### 5.5 Filter bar, tabs & chips
- **Chips** (`chip`): dropdown filters (Status, Tenant type, Property type) as bordered 32px controls.
- **Tabs** (`tab`, active `tab-active`): quick saved views (All / Active / Overdue / Expiring) with a
  `brand-500` underline on the active tab.

### 5.6 Cards & panels
`stat` cards for dashboard metrics (label, tabular value, small note). Content cards use `surface`,
`line` border, `md` radius, `card` shadow, with a 48px header row.

### 5.7 Slide-over
Fixed right panel, `overlay` shadow, translate-in 180ms. Header (id + agreement, title, close),
action bar, then scrollable body: amount-due banner (colour reflects status), field grid, **fine-rule
card** (method + breakdown), recent invoices, activity timeline. Closes on ✕, backdrop, or Esc.

### 5.8 Modal
Centred, `lg` radius, max 480px, `overlay` shadow, dimmed backdrop. Header + close, body, and a
right-aligned footer (`Cancel` subtle + primary action). The **Record payment** modal shows the live
rent/fine allocation breakdown before confirming. Closes on Cancel, backdrop, or Esc.

### 5.9 Flags / toasts
Transient confirmation (`toast`): dark `ink` pill, success tick, one line ("Payment recorded · receipt
2026/121 issued"), auto-dismiss ~3s, bottom-centre. Uses the interface voice and the same verb as the
action ("Record payment" → "Payment recorded").

### 5.10 Avatars
Initials on a semantic colour, 24px in tables / 28px in headers, `full` radius. Organisation vs
individual can be reinforced by the paired tenant-type lozenge.

---

## 6. Key screens (maps to PRD §10)

1. **Dashboard** — five `stat` cards (active leases, billed, collected, arrears [danger ring], fines);
   a "Needs attention · overdue" table; an "Upcoming expiries" list. Answers "what needs me today?".
2. **Leases list** — filter bar + saved-view tabs + issue-list table with property, tenant (+avatar),
   type, status lozenge, right-aligned rent, next-due, overflow menu. Row → slide-over.
3. **Lease detail (slide-over)** — summary, fields, **fine-rule card with breakdown**, recent invoices,
   activity; actions: Record payment, Send reminder, Edit.
4. **Record payment (modal)** — amount, date, method, reference, and a live allocation card
   (rent first, then fine; fine computed on payment date) — directly serving PRD FR-PAY-02/§5.4.
5. **Invoice / receipt (PDF)** — same tokens in print: header (council + tenant + property + period),
   line items, **itemised fine breakdown** (method, days/months, per-tier amounts), total, due date,
   payment account. English, tabular money.
6. **Create menu** — the top-bar **Create** opens a small menu (New lease / tenant / property); each
   leads to a focused full-page guided form.

---

## 7. Interaction & motion

- **Purposeful, fast, minimal.** Slide-over 180ms ease; modal fade+rise ~120ms; toast fade. Nothing
  bouncy or long.
- **Hover/press feedback** on every interactive element; **focus-visible** ring for keyboard users.
- **Respect `prefers-reduced-motion`:** disable transforms/opacity transitions when set.
- **Optimistic, reversible actions** where safe; destructive actions always confirm.

## 8. Accessibility (WCAG 2.1 AA)

- Body text and UI meet **≥4.5:1**; `muted` on white passes for normal text; `faint` is decorative
  only. Semantic foreground/background pairs are checked to pass.
- **Never colour-only:** status is always accompanied by a text label (the lozenge text).
- **Keyboard:** all actions reachable and operable; logical tab order; Esc closes overlays; visible
  focus ring everywhere.
- **Semantics:** real `<table>`, `<button>`, labelled inputs; overlays trap focus and use
  `aria-modal`; icons that stand alone get `aria-label`.
- **Targets:** interactive controls ≥ 32px; comfortable spacing.

## 9. Tailwind implementation

The chosen stack is Laravel + **Livewire + Tailwind + Alpine** (see `../CLAUDE.md`). Filament, if used
for admin screens, accepts the same palette so the look stays consistent.

### 9.1 Tailwind v4 (`resources/css/app.css`, CSS-first)

```css
@import "tailwindcss";
@theme {
  --font-sans: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;

  --color-brand-50:#E9F2FF; --color-brand-100:#CCE0FF; --color-brand-300:#579DFF;
  --color-brand-500:#0C66E4; --color-brand-600:#0055CC; --color-brand-700:#09326C;

  --color-ink:#172B4D; --color-subtle:#44546F; --color-muted:#626F86; --color-faint:#8590A2;
  --color-line:#DFE1E6; --color-line-2:#EBECF0;
  --color-surface:#FFFFFF; --color-sunken:#F7F8F9; --color-hover:#F1F2F4; --color-selected:#E9F2FF;

  --color-success-bg:#DCFCE7; --color-success-fg:#216E4E;
  --color-warning-bg:#FFF7D6; --color-warning-fg:#974F0C;
  --color-danger-bg:#FFECEB;  --color-danger-fg:#AE2E24;
  --color-info-bg:#E9F2FF;     --color-info-fg:#0055CC;
  --color-discovery-bg:#F3F0FF;--color-discovery-fg:#5E4DB2;

  --radius-sm:3px; --radius-DEFAULT:4px; --radius-md:6px; --radius-lg:8px;

  --shadow-card:0 1px 1px rgba(9,30,66,.25), 0 0 1px rgba(9,30,66,.31);
  --shadow-overlay:0 8px 16px -4px rgba(9,30,66,.25), 0 0 1px rgba(9,30,66,.31);
}
```

### 9.2 Tailwind v3 (`tailwind.config.js`)

Use the `theme.extend` block shown in `../design/ui-prototype.html` (colors, borderRadius, boxShadow,
fontSize). The prototype's `<style type="text/tailwindcss">` block defines the component classes
(`btn-primary`, `loz`, `stat`, `input`, …) and can be lifted straight into `app.css`.

### 9.3 Component classes
Define the reusable classes from §5 in an `@layer components` block so Livewire/Blade markup stays
clean (`<button class="btn-primary">`). Keep one definition per component; don't fork variants inline.

## 10. Handoff notes

- **Prototype is the visual source of truth** for spacing, colour and states: `../design/ui-prototype.html`.
  Open it in a browser (loads Tailwind + Inter via CDN). It demonstrates the shell, dashboard, leases
  table, slide-over, record-payment modal, create menu and toast.
- Build screens in the order in `BUILD_PLAN.md`; apply this spec per screen.
- Any new status must be added to the **§3.1 mapping** before use — no ad-hoc colours.
- **Do:** keep one primary action per view; right-align money; always label status. **Don't:** use
  colour without text; introduce new radii/shadows; place more than one blue button in a view.

---

*This is an original design system inspired by the conventions of the Atlassian Design System; it does
not reproduce Atlassian's proprietary brand, logos, or typeface.*
