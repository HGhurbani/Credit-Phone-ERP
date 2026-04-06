# Credit Phone ERP System

A production-ready multi-tenant SaaS ERP system for installment-based electronics retail businesses.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11, PHP 8.2 |
| Frontend | React 19, Vite 8 |
| Database | MySQL (مُعدّ محليًا) — يمكن استخدام SQLite للتجربة السريعة |
| Auth | Laravel Sanctum (token-based) |
| Permissions | Spatie Laravel Permission |
| Styling | Tailwind CSS v4 |
| HTTP Client | Axios |
| State | React Context + Local state |

## Project Structure

```
erp_sys_cp/
├── backend/           # Laravel API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/    # All API controllers
│   │   │   ├── Middleware/         # EnsureTenantAccess
│   │   │   ├── Requests/           # Form Request validations
│   │   │   └── Resources/          # API Resource transformers
│   │   ├── Models/                 # All Eloquent models
│   │   └── Services/               # Business logic services
│   ├── database/
│   │   ├── migrations/             # All DB migrations
│   │   └── seeders/                # Demo data seeders
│   └── routes/api.php              # All API routes
│
└── frontend/          # React SPA
    └── src/
        ├── api/           # Axios client & API helpers
        ├── components/    # Reusable UI components
        │   ├── layout/    # Sidebar, Topbar, AppLayout
        │   └── ui/        # Table, Modal, Badge, etc.
        ├── context/       # AuthContext, LangContext
        ├── hooks/         # Custom hooks
        ├── i18n/          # Arabic & English translations
        ├── pages/         # All page components
        └── utils/         # format helpers
```

## Getting Started

### Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Edit .env to set your DB credentials (MySQL for production)
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
```

### Frontend Setup

```bash
cd frontend
npm install
npm run dev
```

## الأدوار والصلاحيات (Roles & Permissions)

الصلاحيات مُعرَّفة في **Spatie Laravel Permission** (`database/seeders/RolePermissionSeeder.php`).  
أسماء الصلاحيات مثل: `customers.view`, `orders.create`, `payments.collections`, … إلخ.

### دور كل مستخدم — ماذا يظهر في القائمة الجانبية؟

| الدور | الوصف | أهم ما يظهر له |
|--------|--------|-----------------|
| **super_admin** | مدير المنصة (كل المستأجرين لاحقاً) | **كل الشاشات** — يتجاوز فحص الصلاحية في الواجهة (`is_super_admin`) |
| **company_admin** | مدير الشركة (مستأجر واحد) | لوحة التحكم، العملاء، المنتجات، الطلبات، العقود، التحصيل، الفواتير، التقارير، المستخدمون، الفروع، الإعدادات |
| **branch_manager** | مدير فرع | نفس العمليات التشغيلية للفرع؛ **لا** حذف مستخدمين/فروع على مستوى الشركة إن لم تُضف لاحقاً في السياسات — الصلاحيات الحالية أوسع من موظف مبيعات (اعتماد طلبات، تقارير، …) |
| **sales_agent** | موظف مبيعات | لوحة التحكم، **العملاء**، **المنتجات** (عرض)، **الطلبات** (إنشاء/عرض) — **بدون** عقود، تحصيل، فواتير إدارية، تقارير مالية، مستخدمين، فروع، إعدادات |
| **collector** | محصل | لوحة التحكم، عملاء (عرض)، **عقود**، **تحصيل**، **فواتير** (عرض) |
| **accountant** | محاسب | لوحة التحكم، عملاء/طلبات/عقود (عرض)، تحصيل (عرض)، فواتير، **تقارير** وتصدير |

> **التحكم في الواجهة:** القائمة الجانبية تُصفّى حسب `permissions` المرسلة مع المستخدم (بعد تسجيل الدخول أو `/auth/me`).  
> **التحكم في الـ API:** حالياً معظم المسارات محمية بـ `auth:sanctum` + `tenant.access` فقط؛ لفرض الصلاحية على كل endpoint يُفضّل إضافة `middleware('permission:...')` أو **Policies** على المدخلات الحساسة.

### كيف تعدّل الصلاحيات؟

1. **إضافة/تعديل أدوار:** في لوحة الإدارة (مستقبلاً) أو عبر Tinker / جداول `roles` و `role_has_permissions`.  
2. **ربط مستخدم بدور:** جدول `model_has_roles` أو `$user->assignRole('sales_agent')`.  
3. **إعادة التشغيل بعد تعديل الصلاحيات:**  
   `php artisan permission:cache-reset` (إن كان التخزين المؤقت مفعّلاً).

## Demo Credentials

| Role | Email | Password |
|------|-------|----------|
| Super Admin | superadmin@creditphone.com | password |
| Company Admin | admin@creditphone.com | password |
| Sales Agent | agent@creditphone.com | password |
| Collector | collector@creditphone.com | password |

## API Endpoints

| Endpoint | Description |
|----------|-------------|
| POST /api/auth/login | Login |
| GET /api/auth/me | Current user |
| POST /api/auth/logout | Logout |
| GET /api/dashboard | Dashboard stats |
| GET /api/customers | List customers |
| POST /api/customers | Create customer |
| GET/PUT/DELETE /api/customers/{id} | CRUD |
| GET /api/products | List products |
| POST /api/products | Create product |
| POST /api/products/{id}/stock | Adjust stock |
| GET /api/orders | List orders |
| POST /api/orders | Create order |
| POST /api/orders/{id}/approve | Approve order |
| POST /api/orders/{id}/reject | Reject order |
| GET /api/contracts | List contracts |
| POST /api/contracts | Create contract from order |
| GET /api/contracts/{id}/schedules | Get schedules |
| GET /api/collections/due-today | Due today |
| GET /api/collections/overdue | Overdue |
| POST /api/payments | Record payment |
| GET /api/invoices | List invoices |
| GET /api/branches | List branches |
| GET /api/users | List users |
| GET /api/reports/sales | Sales report |
| GET /api/reports/collections | Collections report |
| GET /api/reports/active-contracts | Active contracts |
| GET /api/reports/overdue-installments | Overdue report |
| GET /api/reports/branch-performance | Branch stats |
| GET /api/reports/agent-performance | Agent stats |

## Roles & Permissions

| Role | Description |
|------|-------------|
| super_admin | Platform administrator, manages all tenants |
| company_admin | Manages one tenant fully |
| branch_manager | Manages branch operations |
| sales_agent | Creates orders and customers |
| collector | Records payments and collections |
| accountant | Views financial reports |

## Database Schema

Core tables:
- `tenants` - Multi-tenant companies
- `branches` - Branches per tenant
- `users` - Users with tenant/branch assignment
- `customers` - Customer profiles
- `guarantors` - Customer guarantors
- `customer_documents` - Uploaded docs
- `products` - Product catalog
- `categories` / `brands` - Product taxonomy
- `inventories` - Stock per branch
- `stock_movements` - Stock audit log
- `orders` - Cash/installment orders
- `order_items` - Order line items
- `installment_contracts` - Contracts
- `installment_schedules` - Monthly schedule per contract
- `invoices` - Invoice records
- `payments` - Payment records
- `receipts` - Receipt records
- `collection_logs` - Collection activity
- `settings` - Tenant settings
- `audit_logs` - Audit trail

## Business Workflows

### Order Flow
1. Select/create customer → Add products → Choose type (cash/installment) → Save as draft
2. Manager approves order
3. If cash: auto-generate invoice, deduct stock
4. If installment: convert to contract

### Contract Flow
1. Approved installment order → Create contract → Enter down payment & duration
2. System auto-calculates monthly installment
3. System auto-generates full payment schedule
4. Stock deducted, order marked as "converted"

### Collection Flow
1. View due today / overdue schedules
2. Record payment → apply to schedule automatically
3. Contract balance updates automatically
4. Receipt generated
5. Contract status updates (active/overdue/completed)

## Language Support

- Arabic (RTL) - default
- English (LTR)
- Switch via language button in topbar
- All UI labels, statuses, and messages are translatable
- i18n files: `frontend/src/i18n/ar.js` and `frontend/src/i18n/en.js`

## MySQL — تشغيل محلي (Local)

1. **شغّل خدمة MySQL** (XAMPP / Laragon / MySQL Server / MariaDB) وتأكد أن المنفذ `3306` يعمل.

2. **أنشئ قاعدة البيانات** (مرة واحدة)، من سطر أوامر MySQL أو phpMyAdmin:

   ```sql
   CREATE DATABASE credit_phone_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **عدّل `backend/.env`** (القيم الافتراضية للتطوير المحلي):

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=credit_phone_erp
   DB_USERNAME=root
   DB_PASSWORD=          # ضع كلمة المرور إن وُجدت
   ```

4. **طبّق الجداول والبيانات التجريبية:**

   ```bash
   cd backend
   php artisan config:clear
   php artisan migrate --force
   php artisan db:seed --force
   php artisan storage:link
   ```

5. **شغّل الخادم والواجهة:**

   ```bash
   # Terminal 1 — API
   php artisan serve

   # Terminal 2 — React (من مجلد المشروع)
   cd ../frontend && npm run dev
   ```

   - الواجهة: [http://localhost:5173](http://localhost:5173)  
   - الـ API: [http://localhost:8000](http://localhost:8000)

إذا فشل الاتصال بـ MySQL، تحقق من تشغيل الخدمة، ومن اسم المستخدم/كلمة المرور، وأن قاعدة `credit_phone_erp` موجودة.

### العودة إلى SQLite (بدون MySQL)

في `backend/.env`:

```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

ثم احذف أسطر `DB_HOST` و`DB_USERNAME` و`DB_PASSWORD` أو علّقها، وأنشئ الملف `database/database.sqlite` إن لزم.
