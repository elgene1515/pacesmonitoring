# Project Upgrade Notes

**Date:** 2026-10-19
**Result:** Laravel 9 → **Laravel 13.37**, Vite 4 → **Vite 8.3**, MySQL → **SQLite**. Designs unchanged, still local-only (`php artisan serve` + `npm run dev`).

## Versions

| Part | Before | After |
|------|--------|-------|
| PHP | 8.2 (project said `^8.0.2`) | 8.5 installed, project requires `^8.3` |
| Laravel | 9.x | 13.37 |
| Sanctum | 3.x | 4.3 |
| Tinker | 2.x | 3.0 |
| PHPUnit | 9.6 | 12.5 |
| Carbon | 2.x | 3.15 |
| Vite | 4 | 8.3 |
| laravel-vite-plugin | 0.7 | 3.2 |
| @vitejs/plugin-vue | 4.5 | 6.0.10 |
| Vue | 3.5.x | 3.5.44 |
| vue-router | 4.5 | 4.6.4 |
| Pinia | 3.0.3 | 3.0.4 |
| axios | 1.1 | 1.20 |
| Database | MySQL | SQLite (`database/database.sqlite`) |

Pinia 4 and vue-router 5 exist but were intentionally **not** adopted (separate major versions, not required by Laravel 13).

## Database: MySQL → SQLite

- The database is the file `database/database.sqlite` inside the project, so it moves with the folder.
- `.env` uses `DB_CONNECTION=sqlite`; `DB_DATABASE` is left unset so Laravel resolves the path from wherever the project lives. The old MySQL settings are commented out in `.env`.
- The SQLite database started empty (fresh migrations, no data carried over from MySQL). Old MySQL dumps (`*.sql` in the project root) were not imported.
- `database/.gitignore` still excludes `*.sqlite*`. If git is adopted later, remove that line so the database is committed.

## Project structure changes (Laravel 13 layout)

Removed (all were identical to stock Laravel 9, nothing custom was lost):

- `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`
- `app/Providers/` Auth, Event, Route and Broadcast providers
- Stock middleware: `Authenticate`, `EncryptCookies`, `PreventRequestsDuringMaintenance`, `RedirectIfAuthenticated`, `TrimStrings`, `TrustHosts`, `TrustProxies`, `ValidateSignature`, `VerifyCsrfToken`
- `tests/CreatesApplication.php`, `routes/channels.php`
- `config/broadcasting.php`, `config/hashing.php`, `config/view.php`, stock `lang/`

Replaced or updated:

- `bootstrap/app.php` now holds the app setup and carries your customizations:
  - `role` and `tenant` middleware aliases (`EnsureUserHasRole`, `EnsureTenantAccess`)
  - Sanctum SPA cookie auth on the API (`statefulApi`) and the `api` rate limiter (`throttleApi`)
  - Guests redirected to `/login` (the SPA owns the login page)
  - JSON error responses for every `/api/*` request, even without an `Accept` header
- `app/Providers/AppServiceProvider.php` defines the 60 requests/minute `api` rate limiter.
- `bootstrap/providers.php`, `public/index.php`, `artisan`, `tests/TestCase.php`, `app/Http/Controllers/Controller.php`, `.gitignore` replaced with the Laravel 13 versions.
- `config/` files (`app`, `auth`, `cache`, `database`, `filesystems`, `logging`, `mail`, `queue`, `services`, `session`, `sanctum`) replaced with Laravel 13 versions, keeping your values:
  - `config/auth.php` password reset table kept as `password_resets`
  - `config/sanctum.php` stateful domains kept as `localhost,localhost:8000,localhost:5173,127.0.0.1,127.0.0.1:8000,::1`
  - Sanctum's CSRF middleware reference updated to `PreventRequestForgery` (Laravel 13 rename)
- `.env`, `.env.example`, `phpunit.xml`, `composer.json`, `package.json` updated. `.env` keys renamed for Laravel 13 (`CACHE_STORE`, `BROADCAST_CONNECTION`, `LOG_STACK`, ...); `MAIL_MAILER` is now `log`.
- `APP_KEY` and the `APP_URL` / deployment toggles in `.env`, `vite.config.js` and `resources/js/bootstrap.js` were left as they were.

Untouched: all controllers, services, models, requests, resources, enums, rules, custom middleware, `routes/api.php`, `routes/web.php`, `config/saas.php`, `config/cors.php`, the Artisan command `user:make-owner`, and every Vue file and CSS file.

## Migrations fix

Laravel 11 removed Doctrine DBAL, so six migrations that used `$sqlite ? dropColumn(...) : dropConstrainedForeignId(...)` failed on a fresh SQLite database ("unknown column in foreign key definition"). They now use `dropConstrainedForeignId(...)` everywhere (Laravel 13 rebuilds the SQLite table itself):

- `2026_02_01_000001_scope_records_to_users`
- `2026_02_01_000002_create_school_years_table`
- `2026_02_01_000003_add_school_year_to_grade_levels`
- `2026_02_02_000002_drop_school_year_from_paces`
- `2026_03_01_000006_add_tenancy_to_users_table`
- `2026_03_01_000007_move_school_years_to_organizations`

Your existing `database.sqlite` was already migrated and was not changed.

## Verification

- `php artisan test`: **158 passed (1518 assertions)**, in-memory SQLite.
- Fresh migrate, full rollback and re-migrate on a throwaway SQLite file: all 27 migrations ran both ways.
- `php artisan serve` smoke test: SPA shell and deep links return 200, unknown `/api/*` returns JSON 404, unauthenticated `/api/user` returns JSON 401, missing CSRF token returns 419, valid token returns validation errors (422), wrong login returns 422 from SQLite.
- `npm run build` succeeds; `npm run dev` starts Vite 8 and serves `app.js` and the Vue files.
- `php artisan list` still shows `user:make-owner`.

## What to do on each device

1. Restart `npm run dev` (stop any old Vite 4 server first).
2. Log in again. Laravel 13 uses JSON session serialization, so old sessions are invalid.
3. On another device after copying the folder:
   - PHP 8.3 or newer with `pdo_sqlite` enabled
   - Node 20.19+ or 22.12+ (Vite 8 requirement)
   - `composer install` and `npm install`
   - If `database/database.sqlite` is missing, create an empty file and run `php artisan migrate`

## Notes

- A full copy of the project before the upgrade (minus `vendor` and `node_modules`) is at `C:\Users\qad069\Desktop\Gene Files\Systems\test\pacesmonitoring_backup_laravel9_2026-10-19`. Delete it once you are happy with the upgrade.
- `storage/logs/laravel.log` contains error entries from a failed test run during the upgrade (the migration issue above). Safe to clear.
- `pacesmonitoring.md` is an older doc copy and still mentions Laravel 9 and MySQL. `README.md` was updated.
- The project is not in git. Before the next large change, make a backup copy or run `git init` and commit.
