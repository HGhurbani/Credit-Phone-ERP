# Credit Phone ERP Frontend (React SPA)

This application is the tenant-facing web interface for Credit Phone ERP.

## Stack

- React 19
- Vite 8
- Tailwind CSS v4
- Axios
- React Router

## Responsibilities

- Authentication and session bootstrap
- Role-aware navigation and protected pages
- Customer, order, contract, payment, and reporting screens
- Localization support (Arabic/English)
- Dashboard visualizations and operational workflows

## Local Setup

```bash
npm install
npm run dev
```

## Build

```bash
npm run build
npm run preview
```

## Environment Configuration

Create/update `.env` values:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
```

For production deployments with split subdomains (example):

```env
VITE_API_BASE_URL=https://api.example.com/api
```

## Deployment Notes

- Build outputs are generated under `dist/`.
- Shared-hosting SPA routing relies on `public/.htaccess` being copied into build artifacts.
- Ensure API and frontend origins match backend CORS/Sanctum configuration.

## Quality Checks

```bash
npm run lint
npm run build
```
