# Credit Phone ERP — Tenant Operations Guide

> Despite the filename suffix (`-ar`), this guide is now fully maintained in English.

## 1. Who This Guide Is For

This document is for tenant-side users (company operations), including:

- Company administrators
- Branch managers
- Sales agents
- Collectors
- Accountants

It does **not** focus on platform-level (`super_admin`) subscription administration.

## 2. Daily Operating Model

A tenant represents one company account with independent:

- Branches
- Users
- Customers
- Products and stock
- Orders and installment contracts
- Payments, invoices, and reports

## 3. First-Time Setup Checklist

For new tenants, recommended onboarding sequence:

1. Configure company settings.
2. Create branch records.
3. Create users and assign roles.
4. Build catalog (categories, brands, products).
5. Prepare inventory (purchase entries or adjustments).
6. Verify invoice and installment settings.
7. Run a full test transaction (order → contract → payment).

## 4. Login and Language

- Users sign in with email and password.
- Menus and pages are permission-aware.
- UI supports Arabic and English.
- RTL/LTR switches by language.

## 5. Sidebar Modules (Permission-Based)

Depending on role, users may access:

- Dashboard
- Customers
- Products, categories, brands
- Orders and contracts
- Collections and payments
- Invoices
- Suppliers and purchases
- Cashbox / movement logs / journal entries / expenses
- Reports
- AI assistant
- Users and branches
- Settings

## 6. Module Usage Guide

### 6.1 Settings

Configure:

- Company profile (name, contacts, legal details)
- Installment pricing behavior
- Invoice format and branding
- AI assistant providers and API keys
- Optional Telegram integration

### 6.2 Branches

Manage branch lifecycle:

- Create, edit, activate/deactivate branches
- Maintain branch contact and location details

### 6.3 Users and Roles

Manage user accounts:

- Create and edit users
- Assign role and branch
- Set preferred language
- Activate/deactivate accounts

**Best practice:** assign minimum required permissions only.

### 6.4 Customers

Use customer profiles to centralize operational data:

- Identification and contact details
- Linked orders/contracts
- Statement visibility
- Collection notes and reminders

### 6.5 Products and Inventory

Maintain catalog and stock:

- Categories + brands
- Product pricing and installment constraints
- Branch-level quantity updates
- Stock audit movements

### 6.6 Orders

Order lifecycle:

1. Create order for customer.
2. Add products and quantities.
3. Choose cash or installment.
4. Submit for approval.
5. Approve/reject by authorized role.

### 6.7 Contracts

After installment order approval:

- Convert order into contract
- Enter duration and down payment
- Validate financed amount
- Generate schedule
- Track contract state until completion

### 6.8 Collections

Collector/accounting workflow:

- Review due/overdue queue
- Register payment
- Apply payment to schedule
- Issue receipt
- Add follow-up remarks when needed

### 6.9 Invoices and Receipts

Use transaction artifacts for:

- Customer documentation
- Financial reconciliation
- Internal and external auditing

### 6.10 Reports

Use reports for management decisions:

- Sales trends
- Collection efficiency
- Overdue aging
- Branch and agent performance

## 7. Recommended Role Practices

- **Company Admin**: configuration, governance, approvals, exception handling.
- **Branch Manager**: branch throughput, quality checks, issue escalation.
- **Sales Agent**: customer qualification, accurate order capture.
- **Collector**: consistent follow-up cadence and payment discipline.
- **Accountant**: reconciliation, financial validation, reporting hygiene.

## 8. Operational Controls

- Review overdue installments daily.
- Reconcile invoices/payments at end of day.
- Audit user access monthly.
- Track exceptional events (waivers, schedule changes, corrections).
- Preserve document and receipt history for compliance.

## 9. Troubleshooting Tips

- If a page is missing: verify assigned role/permission.
- If login fails: check credentials, account status, and API URL config.
- If payment posting fails: validate contract state and required fields.
- If reports mismatch: verify date filters, branch scope, and posting completeness.

## 10. KPI Recommendations

Operational KPIs to monitor weekly:

- Collection ratio
- Overdue installment count and value
- Average days past due
- Order approval turnaround time
- Branch-level conversion from order to paid installments

## 11. Related Documents

- `README.md`
- `docs/system-guide-ar.md`
- `docs/hostinger-shared-hosting.md`
- `docs/qatar-compliance-checklist.md`
