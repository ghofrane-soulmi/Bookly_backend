# Bookly — API

Laravel 13 API behind **Bookly**, a multi-tenant booking and business-management SaaS for salons, studios and other appointment-based businesses.

One API serves two apps:

- **[Back office](https://github.com/ghofrane-soulmi/Bookly_frontend)**: staff manage services, clients, appointments, invoices, staff and roles.
- **[Front office](https://github.com/ghofrane-soulmi/Bookly_frontoffice)**: customers open a business's public page, see live availability, book, and manage their bookings.

> **Live demo**
> - Website and booking: **<https://bookly-tn.vercel.app>** (demo salon: [/b/demo-salon](https://bookly-tn.vercel.app/b/demo-salon), customer `customer@demo.bookly.test` / `123456789`)
> - Back office: **<https://bookly-admin-tn.vercel.app>** (owner `owner@demo.bookly.test` / `123456789`)
> - API: <https://bookly-api-tn.vercel.app/api/public/businesses/demo-salon>
>
> The demo resets itself every night (fresh appointments and invoices), and the shared demo logins can't have their email or password changed.

## Highlights

- **Multi-tenancy that fails closed.** Every tenant model goes through a global scope; querying one with no tenant set throws instead of silently returning every business's data. Cross-tenant IDs are rejected at validation.
- **Roles and permissions per business**: spatie/laravel-permission with teams. Owner, admin and staff roles are seeded for each business, and every route is permission-gated. The login response returns the user's permissions so the UI only shows what the API allows.
- **Two separate JWT guards.** Staff (`api`) and customers (`customer`) authenticate independently. Tokens are bound to their model class, so neither works on the other's routes (covered by tests).
- **Scheduling engine** (`AppointmentScheduler`): opening hours, per-staff conflict detection with row locks against double-booking races, free-slot generation for the public booking page, and correct timezone handling.
- **Invoices with exact money.** Integer minor units, currency-aware decimals (3 for TND), server-side totals, discount and tax, payments, and states derived as draft, sent, partial, paid or past due. Completed appointments turn into a draft invoice in one call, and can't be invoiced twice.
- **Emails**: booking confirmation, cancellation, reschedule, reminder, password reset and invoice. A mail outage never breaks a committed booking.
- **Serverless-ready**: runs on Vercel with the community PHP runtime. Reminders are triggered through a secret-protected HTTP hook, pinged hourly by GitHub Actions, and a daily Vercel Cron rebuilds the public demo (`php artisan demo:reset`).

## Architecture

Modular monolith ([nwidart/laravel-modules](https://github.com/nWidart/laravel-modules)), one module per domain:

| Module | Responsibility |
|---|---|
| `Tenant` | Businesses, tenant context and global scope, settings and opening hours |
| `Auth` | Staff accounts, JWT auth, roles and permissions, staff management |
| `Services` | Bookable services with duration and price |
| `Clients` | A business's client records (optionally linked to a customer account) |
| `Appointments` | Booking, conflicts, opening hours, emails, reminders, scheduler |
| `Invoices` | Invoices, line items, payments, invoicing from appointments |
| `Booking` | Public business pages and slots, customer accounts and bookings |
| `Dashboard` | KPIs and charts for the back-office home page |
| `Platform` | Shared reference data (currencies) |

**Stack:** PHP 8.4 · Laravel 13 · MySQL 8 (TiDB-compatible) · tymon/jwt-auth · spatie/laravel-permission · brick/money · PHPUnit

## API overview

All routes are under `/api`. Staff routes need a staff JWT plus the listed permission. Customer routes need a customer JWT.

| Area | Endpoints |
|---|---|
| Staff auth | `POST auth/register · auth/login · auth/refresh · auth/logout`, `GET auth/me`, `PUT auth/profile`, password reset |
| Back office | `services/*`, `clients/*`, `appointments/*`, `staff/*`, `roles/*`, `permissions/list`, `business/*`, `dashboard/summary` |
| Invoices | `invoices/list · {id}/detail · create · update/{id} · delete/{id} · {id}/duplicate · {id}/send · {id}/payments · uninvoiced-appointments · from-appointments` |
| Public | `GET public/businesses/{slug}`, `GET public/businesses/{slug}/slots?service_id&date&user_id` |
| Customers | `POST customer/auth/*`, `GET/PUT customer/me`, `GET customer/appointments`, `POST customer/businesses/{slug}/appointments`, `POST customer/appointments/{id}/cancel` |
| Scheduler | `GET/POST cron/reminders`, `GET/POST cron/reset-demo` (Bearer `CRON_SECRET`) |

## Running locally

Requirements: PHP 8.4 (with `intl`, `pdo_mysql`, `mbstring`), Composer, MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# set DB_* in .env, then:
php artisan migrate --seed      # tables + Demo Salon
php artisan serve               # http://127.0.0.1:8000
```

Tip: enable OPcache (`opcache.enable_cli=1`) for `php artisan serve`. Without it, every request recompiles the framework.

## Tests

```bash
php artisan test
```

131 feature tests (SQLite in memory) cover auth and token isolation, tenant isolation, role permissions, scheduling and double-booking, opening hours and timezones, the customer booking flow, invoices (totals, rounding, 3-decimal currencies, payments, states, invoicing appointments) and emails.

## Demo data

`php artisan db:seed` creates the **Demo Salon** (`/b/demo-salon` in the front office): opening hours, 3 staff, 5 services, clients, appointments and invoices in every state. The password for every account is **`123456789`**.

| App | Email | Role |
|---|---|---|
| Back office | `owner@demo.bookly.test` | Owner (everything) |
| Back office | `flen@demo.bookly.test` | Staff (limited permissions) |
| Front office | `customer@demo.bookly.test` | Customer |

## Deployment

The API deploys to Vercel (`vercel.json`, `api/index.php`) with a free MySQL-compatible database. See `DEPLOY.md` in the workspace for the full step-by-step guide.
