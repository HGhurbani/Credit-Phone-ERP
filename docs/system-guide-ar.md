# Credit Phone ERP — Full System Guide

> Despite the filename suffix (`-ar`), this document is now fully maintained in English.

## 1. Purpose

This guide provides a complete product and operational overview of Credit Phone ERP for technical and business stakeholders.

It covers:

- Product scope and architecture
- User roles and permissions
- Functional modules
- End-to-end business workflows
- Setup, deployment, and administration considerations

## 2. Product Overview

Credit Phone ERP is a multi-tenant SaaS ERP tailored for installment-based electronics retail operations.

Each tenant (company) is isolated and has its own:

- Branches
- Users and role assignments
- Customers
- Products and inventory
- Orders, contracts, invoices, payments, and reports
- Settings and business rules

In parallel, a platform-level `super_admin` governs tenant subscriptions and cross-tenant administration.

## 3. High-Level Architecture

### Backend

- Laravel 12 (REST API)
- Sanctum authentication
- Spatie Permission authorization
- MySQL data storage

### Frontend

- React 19 single-page application
- Vite build tooling
- Tailwind CSS styling

### Integration

- Optional AI assistant provider configuration
- Optional Telegram integration for assistant workflows and notifications

## 4. Access Model

Default personas include:

- `super_admin`: platform operations, tenant oversight
- `company_admin`: full tenant control
- `branch_manager`: branch execution and supervision
- `sales_agent`: customer + order intake
- `collector`: payment collection lifecycle
- `accountant`: financial visibility and reconciliation

Permissions are role-based and can be expanded by updating role/permission mappings.

## 5. Core Functional Areas

### 5.1 Dashboard

Operational dashboards present key indicators:

- Daily sales and collection totals
- Active contracts and overdue installments
- New customers and new orders
- Recent payment activity
- Action-oriented alerts

`super_admin` users receive a platform dashboard focused on tenants, plans, and subscriptions.

### 5.2 Customer Management

Capabilities include:

- Customer profile creation and maintenance
- Linked orders and contracts visibility
- Customer statement and payment timeline
- Internal notes and collection follow-up entries

### 5.3 Product, Catalog, and Inventory

Capabilities include:

- Category and brand management
- Product pricing and installment configuration
- Branch-level stock tracking
- Stock movement auditability

### 5.4 Sales Orders

Supports both cash and installment selling models:

- Draft creation
- Manager approval/rejection
- Cash invoice generation
- Installment order conversion to contract

### 5.5 Installment Contracts

Contract creation from approved installment orders includes:

- Down payment capture
- Duration validation
- Financed amount validation
- Installment schedule generation
- Contract status transitions (active, overdue, completed)

### 5.6 Collections and Payments

Supports structured collection operations:

- Due-today and overdue queues
- Payment registration
- Auto-application to schedules
- Remaining balance recalculation
- Receipt issuance

### 5.7 Billing and Receipts

Invoice and receipt artifacts provide:

- Transaction traceability
- Financial audit support
- Customer-proof documentation

### 5.8 Reporting

Standard reports include:

- Sales and collections performance
- Active contracts and overdue exposure
- Branch performance
- Sales agent performance

## 6. Business Workflow Summary

### 6.1 From Lead to Order
1. Register customer.
2. Add products.
3. Select payment model (cash/installment).
4. Submit order for approval.

### 6.2 From Order to Contract
1. Approve installment order.
2. Convert into contract.
3. Validate pricing/down payment/duration constraints.
4. Generate repayment schedule.

### 6.3 From Schedule to Collection
1. Track installments by due status.
2. Post payment.
3. Update contract balances and statuses.
4. Generate receipt and log collection event.

## 7. Language and UX Behavior

- Arabic and English are supported.
- Layout direction switches between RTL/LTR.
- Financial and date values can remain in western digits (`0-9`) for operational consistency.

## 8. Technical Operations

### Local Development (Summary)

Backend:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

Frontend:

```bash
cd frontend
npm install
npm run dev
```

### Production Considerations

- Set strict production environment values (`APP_ENV=production`, `APP_DEBUG=false`).
- Configure CORS + Sanctum stateful domains correctly.
- Enforce HTTPS for both frontend and API hosts.
- Ensure `storage` and `bootstrap/cache` are writable.
- Enable database backups, logs, and queue scheduling.

## 9. Governance and Best Practices

- Follow least-privilege role assignment.
- Keep tenant settings and pricing rules formally reviewed.
- Monitor overdue ratios and collection effectiveness weekly.
- Audit high-risk actions (pricing changes, user role changes, contract edits).
- Maintain environment-specific runbooks for incident response and deployment rollback.

## 10. Related Documents

- `README.md` — repository-level overview
- `docs/tenant-guide-ar.md` — practical user operations guide
- `docs/hostinger-shared-hosting.md` — shared hosting deployment reference
- `docs/qatar-compliance-checklist.md` — regulatory checklist
