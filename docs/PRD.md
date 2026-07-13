# Council Land & Property Lease Management System

**Product Requirements Document — v1.2 (Draft for review)**

> Automated invoicing, configurable late-fine rules, and SMS/email payment reminders for council-administered land and property leases. This document is the reference for development and acceptance testing.

## Document Control

### Revision history

| **Version** | **Date** | **Summary of change**                                                                                                                                          | **Author / Owner** |
|-------------|----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------|
| 0.1         | Jul 2026 | Initial outline from current spreadsheet analysis                                                                                                              | Product / BA       |
| 1.0         | Jul 2026 | First complete draft issued for stakeholder review                                                                                                             | Product / BA       |
| 1.1         | Jul 2026 | Added third fine method (tiered fixed-monthly charge) and strengthened invoice fine-transparency requirements                                                  | Product / BA       |
| 1.2         | Jul 2026 | Set system language to English (removed Dhivehi/Thaana/RTL requirements); recorded chosen technology stack (Laravel TALL: Tailwind, Alpine, Laravel, Livewire) | Product / BA       |

### Reviewers & approvers

| **Role**                  | **Responsibility**                             | **Sign-off** |
|---------------------------|------------------------------------------------|--------------|
| Council Secretary / CEO   | Business owner; approves scope and budget      | ☐            |
| Finance / Revenue Officer | Confirms rent, fine and invoicing rules        | ☐            |
| Legal / Land Officer      | Confirms lease and agreement handling          | ☐            |
| IT / Systems Lead         | Confirms architecture, security & integrations | ☐            |
| Data Protection Officer   | Confirms privacy & data-handling controls      | ☐            |

> **How to read this document.** Requirements are grouped by capability. Each requirement has a unique ID (e.g. FR-INV-03), a priority using MoSCoW (Must / Should / Could / Won’t-now), and enough detail to be independently testable. Section 5 holds the detailed business rules, Section 6 the roles and permissions, and Section 11 the recommended engineering practices.

## 1. Introduction

### 1.1 Purpose

This document specifies the requirements for a Council Land & Property Lease Management System (“the System”). The System replaces the manual spreadsheet register currently used to record leases of land and premises, and it adds automated monthly invoicing, configurable late-payment fines, payment tracking, and automated SMS/email reminders. The document is written so that it can be used to brief a development team, evaluate vendor proposals, and serve as the basis for acceptance testing.

### 1.2 Background – the current way of working

Leases are presently tracked in a single Microsoft Excel workbook (“Kuli Binthakuge Dhaftaru” – Register of Rented Places). The workbook contains a master register sheet plus one hand-maintained ledger sheet per tenant. Analysis of the current workbook shows the following characteristics, all of which the new System must support:

- A **master register** listing each lease: property/land name, land number and size, rate, tenant details, agreement number, lease start and expiry dates, payment terms, status (Active / Terminated) and free-text notes.

- Tenants are **either organisations** (companies with registration numbers such as C-250/2002) **or individuals** (with national ID numbers such as A118342).

- Rent is charged in several ways: most commonly a **rate per square foot × area** (e.g. 53 laari/ft² × 2,000 ft²), sometimes a **flat monthly amount**, and in some cases with a **grace period** (e.g. “first 6 months free”).

- Some leases carry an **additional annual charge (“CSR”)** – either a fixed yearly amount (e.g. MVR 9,000/year) or a percentage of the tenant’s revenue (e.g. 1% of income).

- Rent is due before the 10th of each month, and **late payment attracts a fine (jūrimānā)** that is calculated **differently per agreement** – some ledgers apply a percentage per day late (e.g. 0.5%/day), others a flat amount per day late (e.g. MVR 3.75/day).

- Payments are recorded month-by-month with a receipt number, paid date and the covered period; fines are computed by counting late days.

> **Why replace the spreadsheet.** The workbook already shows internal inconsistencies – the same tenant ledger states a flat MVR 3.75/day fine in its header but computes the fine at 0.5%/day in its table. Manual copy-per-tenant sheets, hand-typed receipt numbers and hand-counted late days are error-prone, hard to audit, and cannot send reminders. The System removes this manual effort and makes the rules explicit, auditable and configurable.

### 1.3 Goals & objectives

1.  Maintain a single, authoritative register of all council land and property leases.

2.  Generate rent invoices automatically each billing cycle from lease data – no manual per-tenant sheets.

3.  Apply late-payment fines automatically using rules that are configurable per lease agreement (flat per day, percentage per day, or a tiered fixed-monthly charge).

4.  Remind tenants of upcoming and overdue payments automatically by SMS (and optionally email).

5.  Give finance staff a clear view of who owes what, and produce receipts and reports on demand.

6.  Enforce role-based access so each user can only do what their job requires, with a full audit trail.

7.  Be easy to use for non-technical council staff, with a clear, consistent English-language interface.

### 1.4 Scope

#### In scope

- Property/land registry; tenant (person & organisation) registry; lease agreement lifecycle.

- Rent/charge configuration (per-ft², flat, grace periods, annual CSR fixed or % of revenue).

- Automatic invoice generation, payment recording, receipting and account statements.

- Configurable fine engine, per-agreement, with full calculation transparency.

- SMS and email notifications with configurable reminder schedules and templates.

- Role-based access control, dashboards, reporting, and audit logging.

#### Out of scope (this release)

- Online tenant self-service portal and online card payment collection (noted as a future phase).

- General council financial accounting / general ledger (the System will expose data for export instead).

- Procurement of the lease agreements themselves (contract drafting/e-signature).

### 1.5 Definitions & glossary

| **Term**               | **Meaning**                                                                                                                                                                |
|------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Lease / Agreement      | A contract under which the council rents a specific piece of land or premises to a tenant for a defined period at a defined rate.                                          |
| Lessee / Tenant        | The party renting from the council – an individual (national ID) or an organisation (company registration number).                                                         |
| Property / Land parcel | The physical asset being leased: identified by name, land number, size (ft²) and usage type (commercial, agricultural, café/restaurant, boat shed, telecom antenna, etc.). |
| Billing cycle          | The recurring period for which rent is charged – typically monthly.                                                                                                        |
| Invoice                | A system-generated demand for payment for one billing cycle, covering rent plus any applicable charges.                                                                    |
| Fine / Jūrimānā        | A late-payment penalty added when an invoice is paid after its due date, computed per the agreement’s configured rule.                                                     |
| CSR charge             | An additional recurring charge on some leases – a fixed annual amount or a percentage of tenant revenue.                                                                   |
| Grace period           | An initial interval of a lease during which no rent is charged (e.g. first 6 months).                                                                                      |
| Laari / Rufiyaa (MVR)  | Maldivian currency; 100 laari = 1 rufiyaa. Rates are often quoted in laari per ft².                                                                                        |
| RBAC                   | Role-Based Access Control – permissions granted via roles rather than to individuals directly.                                                                             |

## 2. Stakeholders & user roles

The System serves several distinct user types inside the council. Their needs shape both the functional requirements and the permission model in Section 6.

| **User / role**           | **What they need to do**                                                                         | **Primary concerns**                  |
|---------------------------|--------------------------------------------------------------------------------------------------|---------------------------------------|
| System Administrator      | Configure the system, manage users and roles, set fine-rule defaults and notification providers. | Control, security, auditability.      |
| Finance / Revenue Officer | Record payments, issue receipts, review overdue accounts, waive or adjust fines with approval.   | Accuracy, speed, reconciliation.      |
| Land / Lease Officer      | Create and maintain leases, tenants and properties; handle renewals and terminations.            | Correct records, renewals not missed. |
| Supervisor / Manager      | Approve fine waivers and adjustments; view dashboards and reports.                               | Oversight, control totals.            |
| Auditor (read-only)       | Inspect records, invoices, payments and the audit trail.                                         | Immutable history, transparency.      |
| Tenant (external)         | Receive invoices and reminders; (future) view statements online.                                 | Clear, timely, correct notices.       |

## 3. Product overview

### 3.1 Product vision

A single, reliable system of record for every council land and property lease that bills tenants automatically, chases late payers automatically, and gives finance and land officers an accurate, always-current picture of the council’s rental income – replacing fragile spreadsheets with auditable, rule-driven automation.

### 3.2 Capability summary

| **Capability**       | **What it delivers**                                                                                                                                      |
|----------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| Registry             | Master records for properties, tenants and leases, replacing the spreadsheet register.                                                                    |
| Charge configuration | Per-lease rent basis (per-ft² / flat), grace periods, and recurring CSR charges.                                                                          |
| Auto-invoicing       | Scheduled generation of monthly invoices from lease terms, with correct due dates.                                                                        |
| Fine engine          | Per-agreement configurable late fines – flat/day, percent/day, or a tiered fixed-monthly charge – applied automatically and shown in full on the invoice. |
| Payments & receipts  | Record payments, allocate to invoices, auto-number receipts, produce statements.                                                                          |
| Notifications        | Configurable SMS/email reminders before and after due dates via a pluggable provider.                                                                     |
| Access & audit       | Role-based permissions and a complete, tamper-evident audit trail.                                                                                        |
| Insight              | Dashboards and reports on income, arrears, upcoming expiries and fine exposure.                                                                           |

### 3.3 System context

The System is used by council staff through a web application. It sends messages to tenants through an external SMS gateway (provider API to be supplied by the council) and, optionally, an email service. It can export financial data to the council’s accounting process. All tenant, lease and payment data is held in the System’s own secure database.

> **Context, in words.** Council staff → \[Lease Management System\] → SMS gateway → Tenant’s phone. The same System → email service → Tenant’s inbox, and → export/report → Council finance. Tenants do not log in during this release; they only receive notices.

## 4. Functional requirements

Priorities use MoSCoW: **Must** = required for launch; **Should** = important but not launch-blocking; **Could** = desirable if time allows.

### 4.1 Property & land registry

Each leasable asset is recorded once and reused across leases. This mirrors the “property name”, “land number” and “land size” columns in the current register.

| **ID**        | **Requirement**                                                                                                                                                                                                                            | **Priority** |
|---------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-PRP-01** | Create, view, edit and archive property records with: property name, land/parcel number, size (ft²), usage type (commercial, agricultural, café/restaurant, tea-shop, boat shed, telecom antenna, vacant land, other), and location notes. | **Must**     |
| **FR-PRP-02** | Prevent a property from being leased to two active tenants at once (flag overlapping active leases on the same parcel).                                                                                                                    | **Must**     |
| **FR-PRP-03** | Show a property’s full lease history (past and current tenants, dates, status).                                                                                                                                                            | **Should**   |
| **FR-PRP-04** | Support a configurable list of usage types managed by an administrator.                                                                                                                                                                    | **Should**   |
| **FR-PRP-05** | Attach scanned documents (title, survey, photos) to a property record.                                                                                                                                                                     | **Could**    |

### 4.2 Tenant / lessee management

A tenant may be an individual or an organisation; the System captures the right identifiers for each and validates them differently.

| **ID**        | **Requirement**                                                                                                                                  | **Priority** |
|---------------|--------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-TEN-01** | Register a tenant as either an Individual (name, national ID) or an Organisation (registered name, company registration number, contact person). | **Must**     |
| **FR-TEN-02** | Capture contact details used for billing and reminders: mobile number(s), email, postal address.                                                 | **Must**     |
| **FR-TEN-03** | Validate that at least one mobile number is present and in a valid format before SMS reminders can be enabled for that tenant.                   | **Must**     |
| **FR-TEN-04** | Prevent duplicate tenants by warning when a national ID or company registration number already exists.                                           | **Should**   |
| **FR-TEN-05** | A single tenant may hold multiple leases; show all of a tenant’s leases and a consolidated balance.                                              | **Must**     |
| **FR-TEN-06** | Maintain change history for tenant contact details (who changed what, when).                                                                     | **Should**   |

### 4.3 Lease agreement management

The lease ties a property to a tenant with commercial terms and a lifecycle status. It corresponds to a row in the current master register.

| **ID**        | **Requirement**                                                                                                                                                                                                                                         | **Priority** |
|---------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-LSE-01** | Create a lease linking one property to one tenant, with: agreement number, agreement date, lease start date, rent-start date (may differ from start), duration (e.g. 10/15/25 years) and expiry date (auto-calculated from start + duration, editable). | **Must**     |
| **FR-LSE-02** | Record lease status: Draft, Active, Terminated, Expired – with a reason/notes field for termination (mirroring the register’s free-text status notes).                                                                                                  | **Must**     |
| **FR-LSE-03** | Auto-derive Expired status when the expiry date passes; stop generating invoices for non-active leases.                                                                                                                                                 | **Must**     |
| **FR-LSE-04** | Flag leases expiring within a configurable window (e.g. 90 days) for renewal action.                                                                                                                                                                    | **Should**   |
| **FR-LSE-05** | Support amendments to an active lease (rate change, area change) with an effective date, preserving prior terms in history.                                                                                                                             | **Should**   |
| **FR-LSE-06** | Enforce a unique agreement number across the System.                                                                                                                                                                                                    | **Must**     |
| **FR-LSE-07** | Attach the signed agreement document (PDF) to the lease record.                                                                                                                                                                                         | **Could**    |

### 4.4 Rent & charge configuration

This section captures the several ways rent is charged today, so invoices can be generated without manual calculation.

| **ID**        | **Requirement**                                                                                                                                                                   | **Priority** |
|---------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-CHG-01** | Support a rent basis of either (a) rate per ft² × area, or (b) a flat periodic amount. For (a) the System computes and displays the resulting monthly rent.                       | **Must**     |
| **FR-CHG-02** | Accept rates expressed in laari or rufiyaa and store amounts consistently in MVR to two decimals.                                                                                 | **Must**     |
| **FR-CHG-03** | Support an initial grace period (e.g. first N months at zero rent) after the rent-start date.                                                                                     | **Must**     |
| **FR-CHG-04** | Support an optional recurring additional charge (“CSR”): either a fixed annual amount or a percentage of declared tenant revenue, on a configurable schedule (annual by default). | **Should**   |
| **FR-CHG-05** | Set the billing cycle per lease (default monthly) and the due day (e.g. 10th of each month).                                                                                      | **Must**     |
| **FR-CHG-06** | Support a security deposit field for reference (not invoiced automatically).                                                                                                      | **Could**    |
| **FR-CHG-07** | Show a clear, itemised preview of what each future invoice will contain before the lease is activated.                                                                            | **Should**   |

> **Worked example (per-ft²).** A 2,000 ft² commercial parcel at 53 laari/ft² → 2,000 × 0.53 = MVR 1,060.00 per month, due before the 10th. This matches the Dhiraagu antenna lease in the current register.

### 4.5 Automatic invoice generation

Invoices are generated automatically on a schedule from active lease terms, removing the manual per-tenant ledger sheets used today.

| **ID**        | **Requirement**                                                                                                                                                                                                                                                                                                                            | **Priority** |
|---------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-INV-01** | Automatically generate invoices for every active lease at the start of each billing cycle, covering the correct period and due date derived from the lease.                                                                                                                                                                                | **Must**     |
| **FR-INV-02** | Each invoice must itemise, in plain language: base rent, any CSR/additional charge due this cycle, and (once late) the accrued fine – including the fine method used, the overdue period (days or months), the tier breakdown and the resulting amount – with a running total, so the tenant can see exactly how every figure was reached. | **Must**     |
| **FR-INV-03** | Assign a unique, sequential, human-readable invoice/receipt number using a configurable format (e.g. YYYY/NNN), never reusing numbers.                                                                                                                                                                                                     | **Must**     |
| **FR-INV-04** | Respect grace periods and lease start/end dates: do not invoice before rent-start (after grace) or after expiry/termination.                                                                                                                                                                                                               | **Must**     |
| **FR-INV-05** | Allow an authorised officer to preview, regenerate (before issue) and manually issue an invoice off-cycle when needed.                                                                                                                                                                                                                     | **Should**   |
| **FR-INV-06** | Never double-invoice a period; guarantee exactly one invoice per lease per cycle (idempotent generation).                                                                                                                                                                                                                                  | **Must**     |
| **FR-INV-07** | Produce a printable/PDF invoice showing tenant, property, period, line items, amount due, due date and payment account.                                                                                                                                                                                                                    | **Must**     |
| **FR-INV-08** | Support proration of the first and last invoice when a lease starts or ends mid-cycle (configurable on/off).                                                                                                                                                                                                                               | **Should**   |
| **FR-INV-09** | If invoice generation fails for any lease, record the error and continue with the others; surface failures to an administrator.                                                                                                                                                                                                            | **Must**     |

### 4.6 Payment recording & receipts

Finance officers record payments against invoices; the System keeps each tenant’s balance current.

| **ID**        | **Requirement**                                                                                                                       | **Priority** |
|---------------|---------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-PAY-01** | Record a payment against a specific invoice with: amount, payment date, method and reference; auto-generate a receipt number.         | **Must**     |
| **FR-PAY-02** | Compute the fine due on the actual payment date (see Section 5) and split a payment across rent and fine transparently.               | **Must**     |
| **FR-PAY-03** | Support partial payments and carry the outstanding balance forward, continuing to accrue fine on the unpaid portion where configured. | **Should**   |
| **FR-PAY-04** | Mark an invoice Paid / Partly paid / Overdue automatically based on payments and due date.                                            | **Must**     |
| **FR-PAY-05** | Produce a per-tenant account statement showing all invoices, payments, fines and running balance (replacing the manual ledger sheet). | **Must**     |
| **FR-PAY-06** | Allow reversal/correction of a wrongly entered payment, with reason and audit trail; never hard-delete financial records.             | **Must**     |
| **FR-PAY-07** | Prevent a receipt number from being edited after issue.                                                                               | **Must**     |

### 4.7 Fine (late-payment) engine

This is a central requirement. Fines must be automatic, transparent and – crucially – configurable per lease agreement, because different agreements use different rules today (some percentage-per-day, some flat-per-day).

| **ID**        | **Requirement**                                                                                                                                                                                                                                                                                                                                                        | **Priority** |
|---------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-FIN-01** | Support at least three fine calculation methods, selectable per lease: (a) Flat amount per day late; (b) Percentage of the overdue amount per day late; (c) Tiered fixed-monthly charge – a set amount for the first overdue month and a (different) set amount for each subsequent overdue month.                                                                     | **Must**     |
| **FR-FIN-02** | Fine configuration is held per lease agreement and can differ between agreements; a system-wide default exists but each lease may override it.                                                                                                                                                                                                                         | **Must**     |
| **FR-FIN-03** | Fine parameters must include: method, rate/amount, the base it applies to (rent only vs. rent + charges), a grace/allowance of N days before fines start (default 0), and an optional maximum cap.                                                                                                                                                                     | **Must**     |
| **FR-FIN-04** | Accrue fine per calendar day late from the day after the due date up to (and including) the payment date; show the number of late days on the invoice and receipt.                                                                                                                                                                                                     | **Must**     |
| **FR-FIN-05** | Recompute the displayed fine dynamically each day for unpaid overdue invoices so staff always see the current amount.                                                                                                                                                                                                                                                  | **Must**     |
| **FR-FIN-06** | Fully itemise every fine: base amount, method, rate, late-day count, and resulting fine – so any figure can be independently verified.                                                                                                                                                                                                                                 | **Must**     |
| **FR-FIN-07** | Allow an authorised supervisor to waive or adjust a fine, with a mandatory reason, captured in the audit trail (see roles in Section 6).                                                                                                                                                                                                                               | **Must**     |
| **FR-FIN-08** | Support rounding rules (e.g. 2 decimal places) configured centrally and applied consistently.                                                                                                                                                                                                                                                                          | **Should**   |
| **FR-FIN-09** | Allow the effective date of a fine-rule change so historic invoices keep the rule that applied when they were issued.                                                                                                                                                                                                                                                  | **Should**   |
| **FR-FIN-10** | For the tiered fixed-monthly method, configuration must include: the set date (or grace days) after which the fine begins, a customizable first-month amount (default MVR 100), a customizable subsequent-month amount (default MVR 50), and how a partial month is counted (default: each commenced overdue month counts as one). All amounts are editable per lease. | **Must**     |
| **FR-FIN-11** | The applicable fine – for every method – must be calculated and added to the invoice automatically as the overdue period grows, with no manual step.                                                                                                                                                                                                                   | **Must**     |
| **FR-FIN-12** | The invoice and account statement must display the full fine breakdown so there is no confusion: the method, the set/due date, the number of overdue days or months, the per-tier amounts, and the running fine total.                                                                                                                                                 | **Must**     |

> **Worked example 1 – per-day methods (matches the current “ABID” ledger).** Rent = MVR 500, method = percentage/day = 0.5%, base = rent only. 0.5% × 500 = MVR 2.50 per day late. If paid 422 days late: fine = 422 × 2.50 = MVR 1,055.00; total due = 500 + 1,055 = MVR 1,555.00. A different agreement could instead be set to a flat MVR 3.75/day, giving 422 × 3.75 = MVR 1,582.50 – both are just configuration on the lease.

> **Worked example 2 – tiered fixed-monthly method (new).** First-month amount = MVR 100, subsequent-month amount = MVR 50 (both editable). If unpaid past the set date: 1 month late → MVR 100. 2 months late → 100 + 50 = MVR 150. 3 months late → 100 + 50 + 50 = MVR 200. In general, n overdue months → 100 + (n − 1) × 50. The invoice shows this line by line – e.g. “First overdue month: 100.00 · 2 further months × 50.00: 100.00 · Fine total: 200.00” – so the tenant sees exactly how it was reached.

### 4.8 Notifications & reminders

The System reminds tenants of upcoming and overdue payments automatically. SMS is the primary channel (provider API to be supplied by the council); email is a secondary channel.

| **ID**        | **Requirement**                                                                                                                                                     | **Priority** |
|---------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-NOT-01** | Send SMS reminders to a tenant’s registered mobile number(s) through a configurable SMS provider (API credentials, endpoint and sender ID set by an administrator). | **Must**     |
| **FR-NOT-02** | Configure reminder schedules relative to the due date, e.g. X days before due, on the due date, and Y days after (overdue) – each toggle-able.                      | **Must**     |
| **FR-NOT-03** | Use editable English-language message templates with merge fields (tenant name, property, period, amount due, fine, due date, payment account).                     | **Must**     |
| **FR-NOT-04** | Let reminder schedules and templates be set as a system default and, optionally, overridden per lease or per tenant.                                                | **Should**   |
| **FR-NOT-05** | Record every message sent (recipient, channel, content, timestamp, provider response/delivery status) for audit and troubleshooting.                                | **Must**     |
| **FR-NOT-06** | Retry transient send failures and clearly flag permanent failures (e.g. invalid number) to staff.                                                                   | **Must**     |
| **FR-NOT-07** | Never send reminders for fully paid invoices; stop overdue reminders once an invoice is settled.                                                                    | **Must**     |
| **FR-NOT-08** | Provide a manual “send reminder now” action for a selected invoice or tenant.                                                                                       | **Should**   |
| **FR-NOT-09** | Respect a per-tenant opt-out / quiet-hours setting and avoid duplicate sends within a cycle.                                                                        | **Should**   |
| **FR-NOT-10** | Abstract the provider behind an internal interface so a second/replacement SMS or email provider can be added without changing business logic.                      | **Should**   |

> **SMS provider integration.** The council will supply the SMS gateway API details (endpoint, authentication method, sender ID, message-encoding and rate limits). The System must store these credentials securely (encrypted, not in source code), handle message encoding and multi-part messages correctly, capture the provider’s delivery-status callbacks where available, and be testable against a sandbox before go-live. See Section 8.1.

### 4.9 Dashboards & reporting

| **ID**        | **Requirement**                                                                                                               | **Priority** |
|---------------|-------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-RPT-01** | Home dashboard showing: total active leases, invoiced this month, collected this month, total arrears, and fines outstanding. | **Must**     |
| **FR-RPT-02** | Arrears/aging report listing overdue invoices by tenant with days overdue and current fine.                                   | **Must**     |
| **FR-RPT-03** | Upcoming lease expiries and grace-period endings within a configurable window.                                                | **Should**   |
| **FR-RPT-04** | Income report by period, property type and tenant type (individual vs organisation).                                          | **Should**   |
| **FR-RPT-05** | Export any report and the underlying data to Excel/CSV and PDF.                                                               | **Must**     |
| **FR-RPT-06** | Fine report: fines accrued, collected, and waived (with reasons) over a period.                                               | **Should**   |

### 4.10 Audit trail

| **ID**        | **Requirement**                                                                                                                                                                                          | **Priority** |
|---------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-AUD-01** | Record an immutable audit entry for every create/update/delete of leases, tenants, invoices, payments, fines, waivers, configuration and user/role changes – capturing who, what, before/after and when. | **Must**     |
| **FR-AUD-02** | Financial records (invoices, payments, receipts) are never hard-deleted; corrections are made by reversing entries.                                                                                      | **Must**     |
| **FR-AUD-03** | Audit log is viewable and filterable by authorised roles and exportable.                                                                                                                                 | **Should**   |

## 5. Business rules

These rules define exactly how the System must behave. They are the reference for acceptance testing.

### 5.1 Rent calculation

8.  If rent basis = per-ft²: monthly rent = area (ft²) × rate, with rate normalised to MVR (laari ÷ 100).

9.  If rent basis = flat: monthly rent = the configured flat amount.

10. During a grace period (from rent-start date for N months) rent = 0 and no invoice is raised, but the period is visible on the statement as “grace – no charge”.

11. CSR charge, when present, is added on its own schedule (annual by default): fixed amount, or percentage × declared revenue for the period.

### 5.2 Due dates & billing

12. Each lease has a due day (default: 10th). The invoice for a cycle is due on that day of the cycle’s month.

13. Exactly one invoice is generated per active lease per cycle.

14. No invoices are generated before the (post-grace) rent-start date, or on/after the expiry or termination date.

### 5.3 Fine calculation

15. Late days = number of calendar days from the day after the due date up to and including the payment date (or “today” if still unpaid), minus any configured allowance days.

16. If late days ≤ 0, fine = 0.

17. Flat method: fine = late days × flat daily amount.

18. Percentage method: daily fine = percentage × base amount; fine = late days × daily fine. Base = rent only, or rent + charges, per configuration.

19. Tiered fixed-monthly method: from the day after the set/due date, count overdue months (each commenced month counts as one, unless configured otherwise). Fine = first-month amount for month 1, plus the subsequent-month amount for every month after the first. For n overdue months: fine = first-month amount + (n − 1) × subsequent-month amount. Amounts default to MVR 100 (first) and MVR 50 (subsequent) and are editable per lease.

20. Whichever method applies, the fine is added to the invoice automatically and its full breakdown (method, set/due date, overdue days or months, per-tier amounts, total) is shown on the invoice and statement.

21. Apply the maximum cap if configured; apply the central rounding rule (default 2 dp).

22. The rule in force is the one configured on the agreement at the time the invoice was issued (rule changes are effective-dated).

### 5.4 Payment allocation

23. A payment settles fine first or rent first per a central, configurable policy (default: rent first, then fine).

24. Partial payments reduce the outstanding balance; remaining rent may continue to accrue fine where configured.

25. An invoice becomes Paid only when rent and any due fine for it are fully settled.

### 5.5 Notifications

26. A reminder is sent only if the tenant has a valid mobile number and has not opted out.

27. Pre-due reminders stop once paid; overdue reminders stop once settled.

28. Every attempted send is logged with its delivery outcome.

## 6. Roles & permissions

Access is granted through roles (RBAC). A user has one or more roles; each role grants a set of permissions. Roles are configurable, and the matrix below is the recommended default. The principle is least privilege: users get only what their job needs, and sensitive actions (fine waivers, configuration, user management) are restricted and always audited.

### 6.1 Permission matrix (default)

| **Action**                             | **Admin** | **Finance** | **Land Off.** | **Supervisor** | **Auditor** |
|----------------------------------------|-----------|-------------|---------------|----------------|-------------|
| Manage properties                      | –         | –           | ✓             | ✓              | –           |
| Manage tenants                         | –         | –           | ✓             | ✓              | –           |
| Create / amend leases                  | –         | –           | ✓             | ✓              | –           |
| Terminate a lease                      | –         | –           | A             | ✓              | –           |
| Configure rent / CSR charges           | ✓         | –           | ✓             | ✓              | –           |
| Configure fine rules                   | ✓         | –           | –             | ✓              | –           |
| Generate / issue invoices              | ✓         | ✓           | –             | ✓              | –           |
| Record payments / receipts             | –         | ✓           | –             | ✓              | –           |
| Waive / adjust a fine                  | –         | A           | –             | ✓              | –           |
| Reverse a payment                      | –         | A           | –             | ✓              | –           |
| Configure notifications / SMS provider | ✓         | –           | –             | –              | –           |
| Manage users & roles                   | ✓         | –           | –             | –              | –           |
| View dashboards & reports              | ✓         | ✓           | ✓             | ✓              | ✓           |
| View audit trail                       | ✓         | –           | –             | ✓              | ✓           |

*✓ = allowed · A = allowed but requires supervisor approval · – = not allowed.*

### 6.2 Access-control requirements

| **ID**        | **Requirement**                                                                                                   | **Priority** |
|---------------|-------------------------------------------------------------------------------------------------------------------|--------------|
| **FR-SEC-01** | Authenticate every user with individual credentials; support strong-password policy and account lockout.          | **Must**     |
| **FR-SEC-02** | Enforce permissions on every action server-side, not only in the UI.                                              | **Must**     |
| **FR-SEC-03** | Support multi-factor authentication for privileged roles (Admin, Supervisor).                                     | **Should**   |
| **FR-SEC-04** | Roles and their permissions are configurable by an administrator.                                                 | **Should**   |
| **FR-SEC-05** | Session timeout after inactivity; secure sign-out.                                                                | **Must**     |
| **FR-SEC-06** | Every privileged action (waiver, config change, user change) is recorded in the audit trail with the acting user. | **Must**     |

## 7. Non-functional requirements

### 7.1 Usability & language

| **ID**         | **Requirement**                                                                                                                                                              | **Priority** |
|----------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| **NFR-USE-01** | The UI must be clean, uncluttered and usable by non-technical council staff with minimal training; common tasks (record a payment, find a tenant) reachable in a few clicks. | **Must**     |
| **NFR-USE-02** | The System, including all screens, invoices, receipts and notifications, is in English, using clear and consistent terminology throughout.                                   | **Must**     |
| **NFR-USE-03** | Consistent layout, clear labels, inline validation and helpful error messages; destructive actions require confirmation.                                                     | **Must**     |
| **NFR-USE-04** | Responsive layout usable on a standard office desktop and on a tablet.                                                                                                       | **Should**   |
| **NFR-USE-05** | Meet accessibility good practice (readable contrast, keyboard navigation, sensible font sizes).                                                                              | **Should**   |

### 7.2 Security & privacy

| **ID**         | **Requirement**                                                                                                   | **Priority** |
|----------------|-------------------------------------------------------------------------------------------------------------------|--------------|
| **NFR-SEC-01** | Encrypt data in transit (HTTPS/TLS) and encrypt sensitive data at rest (credentials, national IDs, SMS API keys). | **Must**     |
| **NFR-SEC-02** | Store secrets (SMS API keys, DB credentials) outside source code in a secure configuration/secret store.          | **Must**     |
| **NFR-SEC-03** | Protect against common web vulnerabilities (OWASP Top 10: injection, XSS, CSRF, broken access control, etc.).     | **Must**     |
| **NFR-SEC-04** | Collect only necessary personal data; restrict access by role; retain per the council’s data-retention policy.    | **Must**     |

### 7.3 Reliability, performance & operations

| **ID**         | **Requirement**                                                                                                            | **Priority** |
|----------------|----------------------------------------------------------------------------------------------------------------------------|--------------|
| **NFR-REL-01** | Scheduled jobs (invoice generation, reminders) must be reliable, idempotent and recover safely after a failure or restart. | **Must**     |
| **NFR-REL-02** | Automated, regular, restorable backups of the database; documented restore procedure.                                      | **Must**     |
| **NFR-PER-01** | Common screens load within ~2 seconds for the expected data volume (hundreds of leases, thousands of invoices/year).       | **Should**   |
| **NFR-PER-02** | Monthly invoice run for all active leases completes well within the billing window.                                        | **Must**     |
| **NFR-OPS-01** | Application and job logs are available for monitoring and troubleshooting; errors are alertable.                           | **Should**   |

## 8. Integrations

### 8.1 SMS gateway

The primary notification channel. The council will provide the SMS provider’s API details. The System integrates through a thin, replaceable adapter.

| **ID**         | **Requirement**                                                                                                      | **Priority** |
|----------------|----------------------------------------------------------------------------------------------------------------------|--------------|
| **INT-SMS-01** | Configure endpoint URL, authentication (API key/token or username-password), and sender ID through admin settings.   | **Must**     |
| **INT-SMS-02** | Handle message content encoding and correct multi-part (concatenated) handling for longer messages.                  | **Must**     |
| **INT-SMS-03** | Capture the provider’s send response and, where supported, delivery-status callbacks; store against the message log. | **Must**     |
| **INT-SMS-04** | Handle rate limits and transient errors with retry/back-off; surface hard failures.                                  | **Should**   |
| **INT-SMS-05** | Provide a sandbox/test mode to validate integration before go-live.                                                  | **Should**   |

### 8.2 Email (secondary)

| **ID**         | **Requirement**                                                                                          | **Priority** |
|----------------|----------------------------------------------------------------------------------------------------------|--------------|
| **INT-EML-01** | Send invoices and reminders by email via a configurable SMTP/email provider where a tenant email exists. | **Should**   |

### 8.3 Finance / export

| **ID**         | **Requirement**                                                                        | **Priority** |
|----------------|----------------------------------------------------------------------------------------|--------------|
| **INT-FIN-01** | Export invoices, payments and fines to Excel/CSV for the council’s accounting process. | **Should**   |
| **INT-FIN-02** | Expose a documented API for future integration (tenant portal, payment gateway).       | **Could**    |

## 9. Conceptual data model

The core entities and their relationships. This is conceptual – the delivery team will refine it into a physical schema.

| **Entity**   | **Key attributes**                                                                                                                                                                                            | **Relationships**                                 |
|--------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------|
| Property     | id, name, land number, size ft², usage type, location, status                                                                                                                                                 | 1 property → many leases (over time)              |
| Tenant       | id, type (individual/org), name, national ID / company reg no, contacts (mobile, email, address)                                                                                                              | 1 tenant → many leases                            |
| Lease        | id, agreement no, property, tenant, agreement/start/rent-start/expiry dates, duration, rent basis, rate/area or flat amount, grace, due day, CSR config, fine config, status                                  | links Property + Tenant; → many invoices          |
| FineRule     | method (flat-per-day / percent-per-day / tiered-monthly), rate or amount, base, set date / allowance days, first-month amount, subsequent-month amount, partial-month handling, cap, rounding, effective date | 1 lease → fine rule(s) (effective-dated)          |
| Invoice      | id, number, lease, period, due date, line items (rent, CSR, fine), amount due, status                                                                                                                         | belongs to Lease; → many payments; → fine accrual |
| Payment      | id, invoice, receipt no, amount, date, method, reference, allocation (rent/fine)                                                                                                                              | belongs to Invoice                                |
| Notification | id, tenant, channel, template, content, sent-at, status/response                                                                                                                                              | belongs to Tenant/Invoice                         |
| User / Role  | user credentials; role; permissions                                                                                                                                                                           | users ↔ roles ↔ permissions                       |
| AuditEntry   | actor, action, entity, before/after, timestamp                                                                                                                                                                | references any entity                             |

## 10. UX & UI requirements

The System must feel simple and reassuring for staff who are moving from a familiar spreadsheet. The following principles and key screens guide the design.

### 10.1 Design principles

- **Task-first.** Design around the jobs staff do daily: find a tenant, record a payment, chase arrears, add a lease.

- **Show the maths.** Whenever a fine or total is shown, the breakdown (base, rate, late days) is visible or one click away – no black boxes.

- **Safe by default.** Confirm destructive or financial actions; never allow silent deletion of financial records.

- **Consistent English terminology.** Use the same clear English labels for the same concept everywhere – in the UI, on invoices and receipts, and in notifications.

- **Low training cost.** Consistent patterns, clear empty states, and sensible defaults so a new user is productive quickly.

### 10.2 Key screens

| **Screen**        | **Purpose & key elements**                                                                                                                                                                                                    |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Dashboard         | At-a-glance totals: active leases, billed/collected this month, arrears, fines outstanding; quick links to overdue accounts and upcoming expiries.                                                                            |
| Leases list       | Searchable/filterable table (by status, tenant type, property type, expiry) mirroring the familiar register, with clear Active/Terminated/Expired badges.                                                                     |
| Lease detail      | All terms, the generated invoice schedule, current balance, fine configuration, and history of amendments – plus actions (record payment, send reminder).                                                                     |
| Tenant detail     | Contact info, all leases, consolidated statement and message history.                                                                                                                                                         |
| Record payment    | Simple form: pick invoice, enter amount/date/reference; System shows computed fine and remaining balance before saving.                                                                                                       |
| Invoice / receipt | Printable English document with line items, due date and payment account – including a clear fine breakdown (method, overdue days/months, per-tier amounts, total) so the tenant sees exactly how each charge was calculated. |
| Arrears           | Overdue invoices with days late and current fine; one-click reminder.                                                                                                                                                         |
| Settings          | Fine-rule defaults, notification schedules/templates, SMS provider, users & roles – restricted by permission.                                                                                                                 |

## 11. Technical approach & engineering best practices

The System will be built to recognised engineering standards so it is secure, maintainable and can evolve. The technology stack is set out below; the practices that follow are expected regardless of implementation detail.

### 11.1 Chosen technology stack

The System will be built on the Laravel “TALL” stack – Tailwind CSS, Alpine.js, Laravel and Livewire – a server-rendered, PHP-centric stack well suited to a role-secured council back-office with heavy data entry, scheduled billing and reporting. It keeps the whole application in one codebase and one language, which lowers the long-term maintenance burden for a small team.

| **Layer**                    | **Technology**                                      | **Why (ties to requirements)**                                                                      |
|------------------------------|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| Framework / language         | Laravel (PHP)                                       | Mature ecosystem; first-class scheduler & queues for auto-invoicing and reminders (FR-INV, FR-NOT). |
| Interactive UI               | Livewire                                            | Reactive screens written in PHP/Blade – no separate JS front end to staff or maintain (NFR-USE).    |
| Styling                      | Tailwind CSS                                        | Utility-first styling for a clean, consistent, easy-to-use interface (§10).                         |
| Light interactivity          | Alpine.js                                           | Small client-side behaviours (menus, toggles) without a heavy framework.                            |
| Admin scaffolding (optional) | Filament                                            | Livewire-based admin panel that can accelerate CRUD, dashboards and RBAC screens (§6, §10).         |
| Database                     | PostgreSQL (or MySQL)                               | Transactional integrity for financial records; exact decimal money.                                 |
| Background work              | Laravel Scheduler + Queues (database or Redis)      | Reliable, idempotent invoice-generation and reminder jobs (NFR-REL-01).                             |
| Notifications                | Laravel Notifications + custom SMS channel          | Channel abstraction realises the provider-adapter requirement (FR-NOT-10).                          |
| Auth / 2FA                   | Laravel Fortify (via official Livewire starter kit) | Login, resets and two-factor for privileged roles (FR-SEC-01, FR-SEC-03).                           |
| Testing                      | Pest                                                | High-coverage unit tests for the invoice and fine calculators (§11.3).                              |

**Recommended supporting packages:** spatie/laravel-permission (roles & permissions, §6); owen-it/laravel-auditing or spatie/laravel-activitylog (audit trail, §4.10); spatie/laravel-pdf or barryvdh/laravel-dompdf (invoice/receipt PDFs, FR-INV-07); and brick/money – or storing amounts as integer minor units (laari) – so currency is never held as a floating-point value.

> **Money handling.** All monetary values must be stored and calculated as exact decimals (or integer minor units), never as floating-point numbers. This is a hard rule for the rent, CSR and fine calculations in Section 5.

### 11.2 Architecture shape

- **Layered application** with a clear separation between the Livewire UI, the business/domain logic, and data access – so rules (especially fine calculation) live in one testable service, independent of the UI.

- **Relational database** (PostgreSQL/MySQL) for transactional integrity of financial records.

- **A reliable job scheduler** for invoice generation and reminders, with idempotent jobs and a record of each run.

- **A provider-adapter pattern** (via Laravel Notification channels) for SMS/email so providers can be swapped without touching business logic.

- **Stateless application services** behind HTTPS, enabling straightforward deployment and future scaling.

### 11.3 Practices expected

| **Area**                | **Expectation**                                                                                                                                                                                    |
|-------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Version control         | All code in Git with peer-reviewed pull requests; no direct changes to the main branch.                                                                                                            |
| Testing                 | Automated unit tests for business rules (invoicing, fine calculation), integration tests for jobs and SMS, and acceptance tests against Section 5. Fine and invoice logic must have high coverage. |
| CI/CD                   | Continuous integration runs tests on every change; repeatable, automated deployments to staging then production.                                                                                   |
| Environments            | Separate development, staging (with SMS sandbox) and production environments.                                                                                                                      |
| Configuration & secrets | Environment-based configuration; secrets in a secure store, never committed to source control.                                                                                                     |
| Security                | Follow OWASP guidance; server-side authorization on every action; dependency and vulnerability scanning.                                                                                           |
| Data integrity          | Financial transactions are atomic; money stored as exact decimals (never floats); append-only for financial history.                                                                               |
| Documentation           | API docs, an admin/operations guide, and end-user help; a data dictionary for the schema.                                                                                                          |
| Observability           | Structured logging, health checks, and error alerting for scheduled jobs and integrations.                                                                                                         |
| Migration               | A repeatable process to import current spreadsheet data (see Appendix A) with validation and a reconciliation report.                                                                              |

## 12. Delivery phases

A phased delivery reduces risk and lets the council benefit early. Each phase is usable on its own.

| **Phase** | **Scope**                                       | **Outcome**                                                                                       |
|-----------|-------------------------------------------------|---------------------------------------------------------------------------------------------------|
| Phase 1   | Registry + charge config + data migration       | All leases, tenants and properties in one place, replacing the spreadsheet; accurate rent set up. |
| Phase 2   | Auto-invoicing + payments/receipts + statements | Monthly invoices generated automatically; payments and receipts tracked; live balances.           |
| Phase 3   | Fine engine + configurable rules + waivers      | Automatic, transparent, per-agreement fines with supervised waivers.                              |
| Phase 4   | SMS + email reminders + templates               | Automated pre-due and overdue reminders through the council’s SMS provider.                       |
| Phase 5   | Dashboards, reports, refinements                | Full reporting and management insight; polish from user feedback.                                 |
| Future    | Tenant self-service portal + online payments    | Tenants view statements and pay online (out of current scope).                                    |

## 13. Acceptance criteria & success measures

### 13.1 Illustrative acceptance criteria

29. Creating an active per-ft² lease produces a correct monthly invoice on the configured due day with the right amount (e.g. 2,000 ft² × 53 laari = MVR 1,060).

30. A lease set to 0.5%/day fine, paid 422 days late on a MVR 500 rent, shows fine = MVR 1,055 and total = MVR 1,555, with the breakdown visible.

31. Switching that lease’s fine rule to flat MVR 3.75/day changes only that lease’s future fines, not others.

32. A lease set to the tiered fixed-monthly method (first month 100, subsequent 50) that is 3 months overdue automatically shows a fine of MVR 200 on the invoice, itemised as first month 100 + 2 × 50, and the amounts can be changed per lease.

33. A tenant with a valid mobile receives the configured pre-due and overdue SMS; each send is logged with a delivery status.

34. A Finance officer cannot configure fine rules or manage users; a waiver by Finance requires supervisor approval and appears in the audit trail.

35. No financial record can be hard-deleted; a wrong payment is corrected by a reversal that remains visible.

### 13.2 Success measures

- Elimination of manual per-tenant ledger spreadsheets.

- Reduction in overdue balances after automated reminders go live.

- Time to record a payment and issue a receipt reduced to under a minute.

- Zero fine-calculation disputes attributable to opaque or inconsistent maths.

## 14. Risks, assumptions & constraints

### 14.1 Key risks

| **Risk**                                                          | **Impact**                     | **Mitigation**                                                                        |
|-------------------------------------------------------------------|--------------------------------|---------------------------------------------------------------------------------------|
| Inaccurate or inconsistent legacy data in the spreadsheet         | Wrong invoices/fines at launch | Clean and validate during migration; reconciliation report; staff sign-off.           |
| SMS provider details/behaviour not finalised                      | Reminder feature delayed       | Adapter pattern; sandbox testing; phase notifications last (Phase 4).                 |
| Tenant data residency / hosting constraints for a government body | Compliance / procurement delay | Confirm residency rules early; plan self-hosted/in-country hosting if required.       |
| Fine-rule ambiguity between agreements                            | Disputes / mistrust            | Make every fine fully itemised and effective-dated; confirm rules with Legal/Finance. |
| Under-adoption if UI is complex                                   | Staff revert to spreadsheets   | Prioritise usability; involve staff in testing; provide training.                     |

### 14.2 Assumptions

- The council will provide SMS gateway API credentials and a sandbox for testing.

- Billing is monthly with a due day (commonly the 10th) unless a lease specifies otherwise.

- Amounts are in Maldivian Rufiyaa (MVR); rates may be entered in laari and normalised.

- Current lease and payment history will be migrated from the existing workbook.

### 14.3 Constraints

- The system interface, invoices, receipts and notifications are in English.

- Must follow the council’s security, data-retention and procurement policies.

### 14.4 Open questions

36. Exact SMS provider, message limits and whether delivery receipts are available.

37. Preferred invoice/receipt numbering format and whether it must continue existing sequences.

38. Default payment allocation policy (rent-first vs fine-first) and whether partial payments accrue further fine.

39. For the tiered fixed-monthly fine: the exact “set date” basis and whether a partial overdue month counts as a full month (assumed yes by default) – to be confirmed with Finance.

40. Whether CSR (% of revenue) needs the council to record declared revenue, and how often.

41. Data-retention periods for tenant personal data and message logs.

## Appendix A – Current spreadsheet → new system mapping

How today’s workbook columns map to the new data model, to guide migration. (Dhivehi column meanings shown in English.)

| **Current spreadsheet field (meaning)**                     | **New entity**     | **New field**                            |
|-------------------------------------------------------------|--------------------|------------------------------------------|
| Property/place name (thanuge nan)                           | Property           | name                                     |
| Land number (bimuge nanbaru)                                | Property           | land number                              |
| Land size (bimuge bodumin, ft²)                             | Property           | size                                     |
| Rate (reyt, e.g. 53 laari/ft²)                              | Lease              | rate + rent basis                        |
| Rent amount (kulige adadu)                                  | Lease / Invoice    | monthly rent                             |
| Payment terms (kuli dhakkanjehey goiy)                      | Lease              | due day / cycle                          |
| Agreement number (agreement nanbaru)                        | Lease              | agreement no                             |
| Leased date / rent-start / expiry                           | Lease              | start / rent-start / expiry dates        |
| Duration (muddatu, e.g. 10 years)                           | Lease              | duration                                 |
| CSR (annual amount or % of income)                          | Lease              | CSR charge config                        |
| Tenant name (nan)                                           | Tenant             | name                                     |
| Registry no (national ID / company reg)                     | Tenant             | national ID / company reg no + type      |
| Phone / email                                               | Tenant             | mobile / email                           |
| Status (Active / Terminated) + notes                        | Lease              | status + notes                           |
| Per-tenant ledger: month, receipt, paid date, amount        | Invoice + Payment  | period, receipt no, payment date, amount |
| Fine header (flat/day) & fine columns (%/day, days, amount) | FineRule + Invoice | method, rate, late days, fine            |

> **End of requirements.** Appendix B below records what was actually decided and delivered during development (July 2026). Requirements above remain the reference; Appendix B wins where the two differ, because it reflects reviewed, shipped behaviour.

## Appendix B — As-built decisions & delivery status (July 2026)

Phases 1–5 (§12) are delivered; the tenant self-service portal remains future. Engineering
guidance lives in `../CLAUDE.md`; UI realisation in `UI_DESIGN_PRD.md` §11.

### B.1 Changes to the assumed stack / locked decisions

| Topic | PRD said | As built |
|---|---|---|
| Database | PostgreSQL (or MySQL) | **MySQL** (`rent_db`); tests on in-memory SQLite |
| PDF engine | spatie/laravel-pdf or dompdf | **spatie/laravel-pdf + browsershot** (Chromium via puppeteer) |
| SMS provider | to be supplied | **MsgOwl** driver implemented (`msgowl`), plus `log`/`null`; no delivery-status callbacks (their API doesn't document any — revisit) |
| xlsx handling | — | **openspout/openspout** added to read the real workbook directly |

### B.2 Provisional answers adopted for the §14.4 open questions

| # | Question | Adopted answer (change only with Finance/Council sign-off) |
|---|---|---|
| 36 | SMS provider | MsgOwl (`rest.msgowl.com`); sender ID configured per account; delivery receipts unavailable |
| 37 | Numbering continuation | **New sequences started at `YYYY/001`** for both invoices and receipts; historical ledger rows NOT imported (blocked on this decision) |
| 38 | Allocation & partials | Rent (principal incl. CSR) first, then fine (`config/billing.php`); partial payments keep accruing fine **on the configured base** while any principal is outstanding; the fine **freezes** once principal is settled |
| 39 | Tiered set date / partial month | Fine starts the day after due (+ allowance days); **each commenced month counts as one** (exact-month boundary stays in the earlier month, day-granular) |
| 40 | CSR % of revenue | Declared revenue is a field on the lease; imported %-CSR leases carry the percentage with revenue unrecorded (warned at import) and bill 0 until it is recorded |
| 41 | Retention | Unresolved — no purge routines built |

### B.3 Requirement interpretations worth knowing

- **§6.1 "A" (approval) cells** — modelled as paired permissions (base + "without approval").
  The approval *workflow* is not built yet: terminate/waive/reverse are currently
  **Supervisor-direct only**; Finance/Land-Officer initiation awaits the workflow.
- **No system-wide default fine rule** (FR-FIN-02's default): a lease without a rule accrues
  no fine, deliberately, until the council states a default.
- **FR-TEN-04 duplicate warning** implemented stricter: national ID / company reg are unique.
- **Manual "send reminder now"** (FR-NOT-08) is gated on `issue invoices` (the matrix has no
  explicit row for it).
- **Overpayments are rejected** — no tenant credit balances in this release.
- **Import quality policy**: structural gaps reject the row; data gaps (missing mobile or
  registry number, %-CSR without revenue) import **with warnings**. The register has no unique
  parcel IDs, so parcel identity is synthesised (name + plot + size) and clashing active
  parcels are split with a warning — staff assign real land numbers afterwards.

### B.4 Deferred requirements (tracked backlog)

Approvals workflow (§6.1 A-cells) · fine waivers + FR-RPT-06 fine report · historical ledger
import (§B.2 #37) · email channel INT-EML-01 · proration FR-INV-08 · configurable usage types
FR-PRP-04 · reminder overrides FR-NOT-04 · quiet hours beyond the 09:00 send ·
property/lease document attachments FR-PRP-05/FR-LSE-07 · data-retention routines (§14.4.41).

Delivered since this appendix was first written: **advance billing** (FR-INV-05 — one invoice
covering N months or the remaining term, monthly run made range-aware so FR-INV-06 holds),
the **tenant detail drill-down** (PRD §10.2 "Tenant detail": consolidated balance, contacts,
message history, lease → invoice → payment levels), and **2FA enrolment + login challenge +
session management** (FR-SEC-03/05) on the self-service account page at `/settings/profile`.
