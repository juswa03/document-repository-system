# Backend setup

These files assume a fresh Laravel 12 app with Sanctum. To wire them in:

```bash
composer create-project laravel/laravel backend
cd backend
composer require laravel/sanctum
php artisan install:api   # publishes Sanctum config + migration
```

Copy these files into place (overwriting where they already exist):
- `app/Models/User.php`
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Middleware/EnsureRole.php`
- `database/migrations/2026_01_01_000001_add_role_to_users_table.php`
- `database/seeders/RoleUserSeeder.php`
- `routes/api.php`

Register the `role` middleware alias in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\EnsureRole::class,
    ]);
})
```

Allow the React dev origin to authenticate (in `.env`):

```
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173
```

This scaffold uses Sanctum **API tokens** (Bearer header), not the
cookie-based SPA guard, so it works across ports with zero CORS/cookie
config beyond enabling CORS for `/api/*` — simplest path for local dev.
For production, consider Sanctum's SPA cookie auth instead for CSRF
protection.

## Database — MySQL

The project standardises on **MySQL** (MariaDB 10.4+ / MySQL 8 both work)
for every environment. Create the two databases once:

```sql
CREATE DATABASE document_repository_system      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE document_repository_system_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`.env` (copy from `.env.example`) already points at
`mysql://root@127.0.0.1:3306/document_repository_system`. The test suite
uses `document_repository_system_test` (wired in `phpunit.xml`).

Then:

```bash
php artisan key:generate
php artisan migrate --seed          # DatabaseSeeder loads lookups + demo accounts
php artisan serve
```

`php artisan db:seed` now runs `LookupDataSeeder` + `RoleUserSeeder` on
its own — no need to call each `--class` by hand.

## Tests

```bash
php artisan test                              # everything
php artisan test --exclude-group=remediation-target   # the CI gate (must stay green)
php artisan test --group=remediation-target            # conformance targets still open
```

`tests/Feature/Conformance/` mirrors Task F of
`docs/conformance-audit-2026-09-01.md`. Tests tagged
`remediation-target` assert behaviour a remediation phase still has to
deliver (`docs/remediation-plan.md`); they turn green as those phases
land. CI (`.github/workflows/ci.yml`) runs the gate as a required check
and the targets as a non-blocking progress signal.

## Document file storage

Uploaded document files live on the **private** `local` disk
(`storage/app/private/documents`), never the web-served `public` disk.
They are reachable only through `GET /api/documents/{id}/file`, which
checks that the caller is the uploader or an admin. `Document::DISK` is
the single source of truth for the disk name.

Upgrading an environment that already stored files under
`storage/app/public/documents`? Run once before deploying:

```bash
php artisan documents:relocate --dry-run   # preview
php artisan documents:relocate             # move public/ -> private/
```

## Schema (matches your ERD)

This adds: `offices`, `request_types`, `categories`, `requests`,
`documents`, `reviews`, `notifications`, `ai_summaries` — plus two
small additions to `users` beyond the ERD:

- `office_id` (FK to `offices`) and `is_active` (boolean) — needed for
  the "employs" relationship and the deactivate toggle on the system
  admin dashboard.
- `name` was renamed to `full_name` to match the ERD. The API still
  returns it as `"name"` in JSON, so the frontend needs no changes.

`ai_summaries` has a model + migration ready for when the LLM
integration is wired up, but no controller yet — nothing calls it.

## New endpoints

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/api/request-types`, `/api/categories`, `/api/offices` | any | dropdown data |
| GET | `/api/notifications` | any | user's notifications |
| PATCH | `/api/notifications/{id}` | any | mark read |
| GET/POST/PATCH | `/api/admin/users...` | system_admin | manage users |
| GET | `/api/osm-admin/queue` | osm_admin | pending requests + documents |
| POST | `/api/osm-admin/reviews` | osm_admin | approve / reject / request revision |
| GET | `/api/dashboard/submissions` | user | own requests + documents |
| POST | `/api/dashboard/requests` | user | submit a request |
| POST | `/api/dashboard/documents` | user | upload a document |

Demo accounts (password for all: `password`):
- `system.admin@example.test` → redirects to `/admin`
- `osm.admin@example.test` → redirects to `/osm-admin`
- `user@example.test` → redirects to `/dashboard`
