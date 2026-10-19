# Deployment reminder

Status as of 2026-10-16: the application is functionally ready for a first
(founding) client. 158 tests pass, the frontend build is current, no
migrations are pending, one owner account exists, three plans are active.
What remains is server configuration plus two product gaps to decide on.

## Must do before going live (configuration, not code)

- [ ] `.env`
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `LOG_LEVEL=info`
  - `APP_URL=https://<your-domain>` (the real address the app is served from)
  - `SANCTUM_STATEFUL_DOMAINS=<your-domain>` only (drop localhost / 127.0.0.1)
  - `SESSION_SECURE_COOKIE=true` (the app must run over HTTPS for the
    cookie session to work safely)
  - `MAIL_*` pointed at a real SMTP account (currently `mailpit`, so nothing
    can be emailed)
- [ ] Switch the two "deployment" lines that are commented out, so they
      match where the app is mounted, then rebuild the assets:
  - `base:` in `vite.config.js`
  - `createWebHistory(...)` in `resources/js/router/index.js`
  - `npm run build` on the server (or copy `public/build`)
- [ ] `config/cors.php`: `allowed_origins` is `['*']` with credentials enabled.
      Set it to your domain.
- [ ] Owner account: change the `admin@admin` password and give it a real email.
- [ ] Data clean-up: rename "Default Organization" and promote one of its
      teachers to school admin from the owner page (or deactivate it if it was
      only test data). Check the second organization in the database is one
      you want to keep.
- [ ] On the server after deploying:
  - `php artisan migrate --force`
  - `php artisan config:cache`, `route:cache`, `view:cache`
- [ ] Database backups on a schedule. The `backup_before_saas_*.sql` files in
      the project root are manual snapshots, not a backup plan.
- [ ] Put the project in git before deploying. It is not a repository today;
      a commit is the record of the state that was tested.

## Product gaps worth a decision

- **No "forgot password" flow.** There is no self-service password reset or
  email verification. A locked-out teacher needs their school admin; a
  locked-out school admin needs the owner. Add before scaling past the pilot.
- **No notifications.** Join requests and plan requests only show inside the
  app (nav badges and lists). Admins and the owner must log in to notice them.
  Fine for a two-branch pilot; add email notifications in the first update.

## Fine as is

- Multi-tenant isolation, role gating (CEO / Admin / School admin / Teacher),
  read-only mode on lapsed subscriptions, plan limits (branches, admins,
  teachers, students), soft-deleted schools and the owner tooling are covered
  by the test suite (`php artisan test`).
- Trial and subscription expiry are computed on read, so no scheduler or queue
  worker is needed; `QUEUE_CONNECTION=sync` is fine.
- API rate limit of 60 requests per minute per user is reasonable for this
  workload.

## One-line summary for the client conversation

The system is ready for a founding-client pilot once the server configuration
above is done; password reset and email notifications come in the first update.

## Operational notes

- Owner accounts are created only with `php artisan user:make-owner {email}`.
- Billing is manual: the owner records subscription periods and approves plan
  requests from Organizations; no payment gateway is involved.
- A lapsed school is read-only, not locked out; a deactivated school is locked
  out but nothing is deleted and it can be restored.
