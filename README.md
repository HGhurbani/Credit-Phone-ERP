# Credit Phone ERP

A production-ready, multi-tenant ERP platform for electronics retailers that sell through cash and installment plans.

## Executive Summary

Credit Phone ERP helps organizations run day-to-day retail operations end to end:

- Customer lifecycle management
- Product, inventory, and branch operations
- Order processing (cash + installment)
- Installment contract generation and collection workflows
- Invoice and receipt handling
- Financial and operational reporting
- Role-based access control across company and branch users

The system is designed for SaaS operation where each tenant (company) has isolated business data, users, settings, and workflows.

## Technology Stack

| Layer | Technology |
|---|---|
| Backend API | Laravel 12, PHP 8.2 |
| Frontend SPA | React 19, Vite 8 |
| Database | MySQL (SQLite optional for quick local experiments) |
| Authentication | Laravel Sanctum |
| Authorization | Spatie Laravel Permission |
| UI Styling | Tailwind CSS v4 |
| HTTP Client | Axios |

## Repository Structure

```text
Credit-Phone-ERP/
├── backend/                    # Laravel API application
├── frontend/                   # React single-page application
├── docs/                       # Deployment and product documentation
└── README.md                   # Project overview
```

## Core Modules

- **Tenant & Branch Management**: Isolated companies with independent users, branches, settings, and transactions.
- **User & Role Management**: Configurable role-based permissions for admin, manager, sales, collector, and accountant personas.
- **Customer Management**: Customer profiles, account statements, contract history, and collection notes.
- **Catalog & Inventory**: Categories, brands, products, stock levels, stock movements, and branch-level inventory visibility.
- **Sales & Orders**: Draft, approval, and fulfillment lifecycle for cash and installment orders.
- **Installment Contracts**: Contract creation, financed amount tracking, and schedule generation.
- **Collections**: Due/overdue monitoring, payment posting, and automated balance reconciliation.
- **Billing**: Invoice and receipt records for auditability.
- **Reporting**: Sales, collections, contracts, overdue installments, branch, and agent performance reports.

## Role Model (Default)

| Role | Typical Responsibility |
|---|---|
| `super_admin` | Platform-level administration across tenants |
| `company_admin` | Full company operations and configuration |
| `branch_manager` | Branch-level supervision and operations |
| `sales_agent` | Customer onboarding and order entry |
| `collector` | Installment follow-up and payment collection |
| `accountant` | Financial review, reporting, and reconciliation |

## Key Business Workflows

### 1) Order Workflow
1. Select or create customer.
2. Add products and choose cash/installment type.
3. Save as draft.
4. Manager approves or rejects.
5. On approval:
   - **Cash**: invoice generation + stock deduction.
   - **Installment**: convert order into contract.

### 2) Contract Workflow
1. Convert approved installment order to contract.
2. Enter down payment, duration, and dates.
3. System validates financed calculations and business constraints.
4. Schedule is generated and contract is activated.

### 3) Collection Workflow
1. Monitor due/overdue installments.
2. Record payment against schedule.
3. System updates remaining balance and contract status.
4. Receipt is generated for traceability.

## Quick Start

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Configure DB credentials in .env
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

## Example Local Credentials (Seed Data)

| Role | Email | Password |
|---|---|---|
| Super Admin | superadmin@creditphone.com | password |
| Company Admin | admin@creditphone.com | password |
| Sales Agent | agent@creditphone.com | password |
| Collector | collector@creditphone.com | password |

## API Surface (Selected)

| Endpoint | Purpose |
|---|---|
| `POST /api/auth/login` | User login |
| `GET /api/auth/me` | Fetch current user and permissions |
| `GET /api/dashboard` | Dashboard metrics |
| `GET/POST /api/customers` | Customer list/create |
| `GET/POST /api/products` | Product list/create |
| `GET/POST /api/orders` | Order list/create |
| `POST /api/orders/{id}/approve` | Approve order |
| `GET/POST /api/contracts` | Contract list/create |
| `GET /api/collections/due-today` | Collection queue for due installments |
| `POST /api/payments` | Record payment |
| `GET /api/invoices` | Invoice list |
| `GET /api/reports/*` | Operational and financial reports |
| `GET/PUT /api/settings` | Tenant settings |

## Deployment Notes

For Hostinger shared hosting deployment and environment examples, see:

- `docs/hostinger-shared-hosting.md`

For detailed product and operations documentation, see:

- `docs/system-guide-ar.md` (full system guide in English)
- `docs/tenant-guide-ar.md` (tenant operations guide in English)
- `docs/qatar-compliance-checklist.md`

## License

This repository is proprietary unless your organization defines a separate license agreement.
