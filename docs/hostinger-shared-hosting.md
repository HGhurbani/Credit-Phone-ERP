# Hostinger Shared Hosting Deployment

This project can be deployed on Hostinger shared hosting with:

- Frontend on `app.your-domain.com`
- Backend API on `api.your-domain.com`

Current stack:

- Frontend: Vite + React SPA
- Backend: Laravel 12 + MySQL + Sanctum

## 1. Recommended domain layout

- `app.example.com` -> frontend static files from `frontend/dist`
- `api.example.com` -> Laravel backend

Because the frontend is a SPA and uses `BrowserRouter`, the frontend host must rewrite unknown routes to `index.html`.

## 2. Frontend build settings

Set the frontend environment before building:

```env
VITE_API_BASE_URL=https://api.example.com/api
```

Then build:

```bash
npm run build
```

Upload the contents of `frontend/dist` to the document root of the `app` subdomain.

This repository now includes `frontend/public/.htaccess`, so Vite will copy it into `dist` during build and Apache can serve SPA routes correctly on Hostinger.

## 3. Backend production environment

Use production values similar to:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

FRONTEND_URL=https://app.example.com
SESSION_DOMAIN=.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
SESSION_DRIVER=database
QUEUE_CONNECTION=database

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

Notes:

- `SESSION_DOMAIN=.example.com` is important so auth cookies work across `app.` and `api.` subdomains.
- `FRONTEND_URL` must match the frontend subdomain because CORS uses it in `backend/config/cors.php`.
- `SANCTUM_STATEFUL_DOMAINS` must include the frontend subdomain used by the browser.

## 4. Backend file layout on Hostinger shared hosting

Hostinger's shared hosting guidance is based around the website root folder that hPanel creates for the site or subdomain. On web/cloud hosting, the root home directory is generally `public_html`, and Hostinger notes that the home directory cannot be changed freely on those plans.

For that reason, the practical shared-hosting layout is:

1. Create `api.example.com` as a separate website or subdomain in hPanel
2. Upload the full Laravel backend into that subdomain root folder
3. Keep Laravel's `public/` folder inside that root
4. Add a root-level `.htaccess` that rewrites traffic into `public/`

Example root-level `.htaccess` for the backend host:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

Resulting layout on the `api` subdomain root:

```text
api-subdomain-root/
  app/
  bootstrap/
  config/
  database/
  public/
  resources/
  routes/
  storage/
  vendor/
  .env
  .htaccess
  artisan
```

If you want a cleaner setup where only Laravel `public/` is exposed as the true document root, VPS is the cleaner option.

## 5. Laravel commands after upload

Run these from the backend folder:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Also make sure these folders are writable:

- `storage`
- `bootstrap/cache`

## 6. Cron and queue

This backend uses queued jobs, including Telegram webhook processing.

On shared hosting, choose one of these:

- Simple option: set `QUEUE_CONNECTION=sync` if you want maximum compatibility and can accept slower webhook/request processing
- Better option: keep `QUEUE_CONNECTION=database` and configure a cron job that runs the queue worker regularly

Useful cron jobs:

```bash
php /home/USERNAME/laravel-api/artisan schedule:run
php /home/USERNAME/laravel-api/artisan queue:work --stop-when-empty
```

If you are not using Telegram assistant features, queue pressure will be lower.

## 7. Final checklist

- Create `app` and `api` subdomains in Hostinger
- Create the MySQL database in hPanel
- Upload backend and frontend files
- Set Laravel `.env` for production
- Build frontend with `VITE_API_BASE_URL=https://api.example.com/api`
- Ensure HTTPS is enabled on both subdomains
- Test login, logout, page refresh, and direct deep links like `/customers`

## 8. Important project-specific fix already included

The frontend previously requested `/sanctum/csrf-cookie` from the frontend host, which breaks when frontend and backend are on different subdomains.

This repository now resolves the CSRF cookie endpoint from the configured API origin, so it works correctly with:

- `https://app.example.com`
- `https://api.example.com`
