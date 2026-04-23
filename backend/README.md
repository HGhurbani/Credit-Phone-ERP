# Credit Phone ERP Backend (Laravel API)

This service provides the multi-tenant REST API for Credit Phone ERP.

## Stack

- Laravel 12
- PHP 8.2
- MySQL
- Laravel Sanctum (auth)
- Spatie Laravel Permission (RBAC)

## Responsibilities

- Tenant-aware authentication and authorization
- Customer, product, inventory, and order APIs
- Installment contract and schedule generation
- Collections, payments, invoices, and receipts
- Reporting endpoints
- Tenant settings management

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure DB credentials in .env
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
```

## Useful Commands

```bash
php artisan test
php artisan migrate:fresh --seed
php artisan permission:cache-reset
php artisan queue:work
```

## Environment Essentials

Set these values carefully per environment:

- `APP_ENV`, `APP_DEBUG`, `APP_URL`
- `DB_*`
- `FRONTEND_URL`
- `SANCTUM_STATEFUL_DOMAINS`
- `SESSION_DOMAIN`
- `QUEUE_CONNECTION`

## Production Hardening Checklist

- Disable debug mode.
- Use HTTPS-only cookies and secure CORS policy.
- Cache config/routes/views.
- Ensure writable `storage/` and `bootstrap/cache/`.
- Configure queue worker + scheduler.
- Enable backups and application monitoring.

## API Contract

Primary API routes are defined in `routes/api.php`. Pair this with top-level documentation in `README.md` for endpoint summaries.
