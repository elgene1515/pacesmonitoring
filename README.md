# PACES Monitoring System

A multi-tenant (SaaS) web application for tracking students and the PACEs
(Packet of Accelerated Christian Education workbooks) assigned to them per
subject, school year and quarter. Each **school (organization)** registers
itself, adds its **branches**, admits its **teachers**, and sets up the school
years everyone works in. Teachers register students and PACEs, assign PACEs
to a student's subjects, then record when each PACE was issued, when it was
tested, the score achieved and whether it is a retake. The school's admins see
the same analytics across every teacher of the school, and the **platform
owner** manages every school, its plan and its subscription from a separate
dashboard.

**Every teacher's records are private to their own account.** Admins are the
exception inside their own school: they can view (not edit) every teacher's
records and manage the teachers' accounts. Schools never see each other.

Right now the system runs locally only (`php artisan serve` + `npm run dev`);
nothing here is tied to a hosting provider.

## Stack

| Layer     | Technology                                                         |
|-----------|---------------------------------------------------------------------|
| Backend   | PHP 8.3+, Laravel 13, Laravel Sanctum (SPA cookie authentication)   |
| Frontend  | Vue 3 (Composition API, `<script setup>`), Pinia, vue-router, Vite 8 |
| Database  | SQLite (`database/database.sqlite`, moves with the project folder)  |
| Styling   | Plain CSS scoped inside each Vue component (minimalist design)      |

Multi-tenancy is the single-database kind: every tenant-owned row carries an
`organization_id` (school years) or belongs to a teacher who belongs to an
organization (grade levels, students, PACEs, PACE records). Nothing in the
local setup changes because of it.

## Roles

Display names: the platform owner shows as **CEO**, platform staff as
**Admin**, a school's administrator as **School Admin**. Platform accounts
list "PACES Monitoring System · Management" where members list their school
and branch.

| Role        | How they get the role | What they can do |
|-------------|-----------------------|------------------|
| **Teacher** | Signs up as a teacher, enters a school code, picks a branch and asks to join; a school admin accepts. | Everything under their own account: grade levels/subjects, students, PACEs and PACE records. Picks their own *current* school year from the school's list. Never sees another teacher's data. |
| **Admin**   | Signs up as a school administrator and registers the school (name, contact details, branches; the first branch is the main one). | Their school only: edit school details, add/rename/remove branches (within the plan's limit), accept or decline join requests, move teachers between branches or remove them, reset their passwords, register school years and set the school default, and a read-only analytics area (Home, Teachers, Students) across every teacher of the school. |
| **Owner**   | `php artisan user:make-owner {email}` — never through sign-up. | The whole platform: every organization (details, branches, members, join requests, plan requests), plans (tiers, limits, prices), each organization's subscription (assign a plan, renew, mark past due / cancel, approve or decline the school's requests), promote a member to admin, the admin analytics for any organization (pick one from Organizations, or look at every organization at once), and — owner only — the platform **staff** (other owners and vice admins), **deactivating** organizations and **deleting** plans. |
| **Vice admin** | Added by the owner on the Staff page. | Everything the owner can do except managing staff, deactivating organizations and deleting plans. |

### Sign-up and onboarding

1. **Create account** – name, email, password, and *School administrator* or
   *Teacher*. A verification email is sent; the account works right away and
   **My account** shows the address as *Not verified* (with a resend button)
   until the link is clicked.
2. **Admin:** *Register your school* – school name, contact details, the
   branches (each named by the admin, with an optional address, one ticked as
   the main branch; the school's address is the main branch's) and a plan:
   the free 30-day trial, or one of the paid plans. The school gets a code such as `SCH-7F3K2A` and starts on
   the trial either way; a paid plan chosen here becomes a **plan request**
   the owner approves (which records the subscription) or declines.
   **Teacher:** *Join your school* – type the school code, pick a branch,
   optionally add a message, send the request. The page shows the pending
   request (it can be withdrawn) until an admin decides; a declined request
   shows the reason and a new one can be sent.
3. Admins see pending requests as a badge on the **Organization** tab and
   accept or decline them there. An accepted teacher lands on their Home page
   the next time they sign in (or press *Check again*).

### School years

School years belong to the school: admins register them (`2026-2027`,
optional start/end dates) and star one as the **school default**. Every
teacher picks from the same list and stars the year they are working in
(their *current* year); new teachers start on the school default. The pick is
personal, so one teacher can still close out last year while the rest of the
school has moved on. Students and PACE records are filed under school years,
and the Home page totals follow the teacher's current year.

### Subscriptions

| Status          | Effect |
|-----------------|--------|
| trial / active  | Full access. |
| past due        | Full access, with a warning banner (grace period). |
| expired / cancelled / none | **Read-only**: everyone in the school can still view records, but nothing can be added or changed until the owner renews. |

A trial or active subscription whose end date has passed counts as expired
automatically (no scheduler involved).

## Accounts and security

- **Sign in** is session based (Sanctum). After **5 failed attempts** for the
  same email and IP the login is locked for **60 seconds**; the Sign in button
  counts down and re-enables itself.
- **Forgot password** (link under the password field): the address receives a
  single-use reset link valid for 60 minutes. The answer is the same whether
  or not the email is registered. School admins can still reset a teacher's
  password from the teacher's page as a fallback.
- **Email verification** is *soft*: sign-up (and changing the email later)
  sends a verification link, the account is usable meanwhile, and My account
  and the user pages show a **Verified / Not verified** badge with a resend
  button. Nothing is blocked while unverified.
- **My account** (click your name in the account menu; `/settings`, every
  role): your details as an admin would see them, plus the forms to change
  name / email and the password. Both forms require your **current
  password**.
- The reset and verification links are built from `APP_URL` and sent through
  the `MAIL_*` mailer; locally (`MAIL_MAILER=log`) they land in
  `storage/logs/laravel.log`. Plans carry a branch limit and
optional admin, teacher and student limits, enforced when an admin adds a
branch, a member is promoted to school admin, an admin admits a teacher or a
teacher registers a student. Billing itself happens
outside the app: the owner records plan assignments and renewals (with notes
such as an invoice number) on the organization's page.

**Plan requests.** A school admin can ask, at any time, to subscribe to or
move to another plan, or to cancel (Organization page → Subscription). One
request is pending at a time and can be withdrawn. The owner sees pending
requests on the Home page and on the organization's page, and either
approves (a plan change records the new subscription period with the dates
the owner picks; a cancellation marks the current subscription cancelled) or
declines with a note the school can read. The owner may also assign or renew
plans by hand without any request.

**Deactivation.** The owner can deactivate a school (a soft delete, password
confirmed): its members are locked out with a "school deactivated" page, its
code can no longer be joined, pending requests are withdrawn, and nothing is
removed. Deactivated schools stay listed under *Organizations → Show
deactivated* and can be restored.

The three tiers from the package deal are inserted by the migrations:

| Plan         | Branches  | Price / year |
|--------------|-----------|--------------|
| Starter      | 1         | ₱15,000      |
| Growth       | up to 2   | ₱25,000      |
| Multi-campus | up to 5   | ₱50,000      |

`config/saas.php` holds the trial length, the default plan, the "expiring
soon" window and the support email shown to lapsed schools
(`SAAS_TRIAL_DAYS`, `SAAS_DEFAULT_PLAN`, `SAAS_EXPIRING_SOON_DAYS`,
`SAAS_SUPPORT_EMAIL` in `.env`).

## Record statuses and shared charts

Every PACE record is in exactly one of three statuses, and the whole UI uses
the same colours for them:

| Status       | Meaning                                              | Colour |
|--------------|------------------------------------------------------|--------|
| **Assigned** | Assigned to the student but not yet issued           | grey   |
| **Issued**   | Issued (has an issued date) but not yet tested       | blue   |
| **Tested**   | Has a test date or a score                           | green  |

On top of the status, a scored record is a **pass** (80 and above) or a
**fail**, and any record can be flagged as a **retake**. The same building
blocks appear throughout the teacher, admin and owner areas: the segmented
PACE records bar, the six-ring layered chart (assigned, issued, tested, pass,
retakes, fail), averages that count unscored PACEs as 0, filterable records
tables and icon actions.

## Features (teacher account)

- **Home** – greeting, filters (school year, grade level, subject, PACE; a PACE
  narrows the records and the PACE count to that one PACE), count
  tiles beside the Progress rings and Score distribution, a getting-started
  checklist, Top performing students, Fails and retakes, PACEs by quarter,
  PACEs by subject and Students by grade level.
  The greeting, action buttons and filters stay pinned under the top bar while
  you scroll (on phones only the greeting and buttons stay, so the stacked
  filters do not cover the page).
- **School years** – the school's list with the teacher's own counts; star
  the year you are working in. (Admins register the years.)
- **Grade levels and subjects** – your own grade levels under a school year,
  each with its subjects; detail pages with rings and records tables.
  A grade level's PACE records can be filtered by subject, school year,
  quarter, status and a score range (either end optional; records without a
  score drop out once a bound is set).
  A subject's PACE records can be filtered by school year, quarter, status and
  the same score range.
  The grade level, subject and PACE detail pages keep their title (and its
  action buttons) pinned under the top bar while you scroll.
- **Students** – master list with search and filters (name, school year, grade
  level, sex and an average-score range; records without a score drop out once
  a bound is set); a student has a name,
  optional extension, birth date, age, sex and grade level. Detail page with
  the ring chart, the PACE Projection table and the PACE Records table.
- **PACEs** – master list (one at a time or as a number range), detail page
  with every assignment. Each PACE has a **stock** (booklets on hand): set it
  on the form, nudge it with − / + on the list or detail page, and filter the
  list by stock level (in stock / low, 5 or fewer / out of stock). Issuing a
  PACE takes one booklet; clearing the issued date or deleting the record
  gives it back. A PACE with no stock can still be assigned, but not issued.
- **Stock requests** (`/paces/requests`) – ask the school admin for booklets
  of a registered PACE (or one named by number and name): quantity, needed-by
  date and a note. Pending requests can be edited or withdrawn; decided ones
  stay as history with the admin's note. Filter the list by status and by the
  date range it was requested in.
- **PACE records** – assign PACEs per subject, school year and quarter;
  record issued date, date tested, score and the retake flag.
- **My account** – your own details, change your name, email and password
  (see *Accounts and security*).

## Account menu and theme (every account)

The member's name in the top bar opens an account menu: who is signed in (the name / email row opens **My account**), the **theme** and **Sign out**. A school admin also sees the school's **plan** (under their name in the bar and in the menu, with its status) and the **school code** with a **Copy** button for handing out to teachers. On small screens the same menu sits at the bottom of the hamburger panel.

The theme is **Light**, **Dark** or **System default** (follows the device and changes live with it). The choice is remembered per browser in `localStorage` (`paces.theme`) and applied before the page paints, so there is no flash. Colours are CSS variables in `resources/css/app.css` (`:root` for light, `:root[data-theme="dark"]` for dark); new components should use the variables instead of literal colours.

## Features (admin account)

Admin navigation: **Home · Users · Students · PACE requests · School years · Organization**.
School admins edit their own details from **My account** (account menu) or
from their own page under Users; either way the current password is required.

- **Home** – the teacher analytics across every teacher of the school, with
  Branch, Teacher, School year, Grade level, Subject and PACE filters (the
  last three need a teacher picked first; a PACE narrows the records and the
  PACE count to that one PACE). The Teachers
  tile, Most active teachers and the recent lists follow the
  branch and teacher filters too. The heading and filters stay pinned under the
  top bar while you scroll (on phones only the heading stays). A school admin's recent students and recent
  members sit at the bottom of the **Organization** page instead of on Home
  (the platform owner's Analytics page keeps them).
- **Users** – every member of the school (search, role and branch filters).
  A member's page shows their school and branch and whether their email is
  verified, edits their details, resets
  their password (with the admin's own password as confirmation), moves them
  to another branch or removes them from the school, and for teachers shows
  their grade levels, students and PACEs read-only, with view links into
  read-only **grade level** and **subject** pages (`/admin/grade-levels/{id}`,
  `/admin/subjects/{id}`). A subject page names its teacher (linked) and the
  teacher's branch, and its PACE records can be filtered by school year and
  quarter as well as by status; its name stays pinned under the top bar while
  scrolling. A PACE page does the same and names the teacher (linked) and branch
  that registered it. A student's page names the teacher's school and
  branch and links each subject's records to its subject page.
  The Students tab of a teacher's page filters by name, school year, grade
  level, sex and an average-score range (either end optional; students with no
  score drop out once a bound is set). The same page serves the platform owner.
- **Students** – every student of the school, filterable by branch, teacher,
  school year and grade level (picking a branch narrows the teacher list to
  that branch; the branch filter appears once the school has more than one),
  sex and an average-score range (either end optional; students without a
  score drop out once a bound is set), with read-only detail pages. The owner
  can also pick a **school** (hidden while the scope bar already narrows to one
  school); picking one reloads the branch, teacher and school year options for
  that school, and with all schools shown each row names the teacher's school.
- **PACE requests** – the teachers' stock requests (badge with the pending
  count on the tab). Filter by status, branch and teacher, search by teacher
  (name or email) or PACE (number or name), and narrow by a "needed by" date
  range (requests with no needed-by date drop out once a range is set). Edit a pending request (PACE, quantity, needed-by date),
  mark it **given** – which adds the quantity to the teacher's stock of that
  PACE when the request points at a registered one – or decline it with a
  note. A teacher's PACEs show their stock on the user and PACE pages.
- **School years** – register and edit the school's years; star the default.
- **Organization** – school details (name and contact details, confirmed with
  the admin's password; the address is read from the main branch) and code
  (copy button), the subscription card (plan, status, period, branches /
  teachers / students used against the limits, with *Choose / Change plan*
  and *Cancel subscription* requests to the provider at the bottom and their
  outcome), branches (add, rename, set the address, make main, remove; with
  teacher and student counts) and join requests (accept / decline with a
  reason, with the decided ones on request).

## Features (owner account)

Owner navigation: **Home · Organizations · Analytics · Users · Students · Plans · Staff**
(Staff is owner-only; vice admins see the rest). Platform accounts edit their
own account from **My account** or from their own page under Users.

- **Home** – organizations, subscriptions by status, read-only (lapsed)
  schools, admins / teachers / students, plan requests waiting, subscriptions
  expiring within 30 days, recent organizations and users, and the pending
  plan and join requests across the platform. Below them, **Accounts not in a
  school yet** lists every teacher or school admin that belongs to no school
  (longest waiting first) with how long ago the account was created, how long
  it has been without a school (since sign-up, or since a school removed it),
  and the state of its latest join request. Filter by name / email, by role
  (teacher or school admin) and by the date range the account was created in,
  and delete strays with your password.
  An account that still owns records (PACEs, students, grade levels, school
  years, stock requests) from a previous school cannot be deleted.
- **Organizations** – every school with plan, status, branches, teachers,
  pending requests and end date (search, status, plan and an "ends" date range
  filter, *Show deactivated*; schools without an end date drop out once a date
  bound is set).
  A school's page edits its details (owner password required), shows and
  manages its subscription (assign a plan, renew, edit a period, quick status
  changes, full history), decides the school's plan requests, lists its
  branches, members (with a role switch between admin and teacher) and join
  requests, jumps into the admin area narrowed to that school, and (owner
  only) deactivates or restores the school.
- **Analytics / Users / Students** – the admin pages, either platform-wide or
  narrowed to the school picked from Organizations (a scope bar under the top
  bar says which, and clears the selection). In a student's PACE records the
  PACE numbers open the read-only admin PACE page.
  Without the scope bar, the Analytics, Users and Students pages let the owner pick a
  school from their own filter (Analytics and Users then offer that school's
  branches too; its branch filter stays disabled until a school is picked,
  since branch names repeat across schools).
- **Plans** – add or edit tiers (branch, teacher and student limits, price),
  retire or restore them, and (owner only) delete a plan nobody has ever
  subscribed to.
- **Staff** (owner only) – add platform accounts as owner or vice admin,
  switch their role, remove them. There is always at least one owner.

## Requirements

- PHP 8.3 or newer (developed on 8.5) with the `pdo_sqlite` extension
  (`pdo_mysql` only if you switch the connection back to MySQL)
- Composer 2
- Node.js 22 (20.19+ also works) and npm

## Setup

1. **Database.** The SQLite database is the file `database/database.sqlite`,
   which travels with the project folder. On a copy that does not have one yet,
   create an empty file; the migrations in step 4 build the schema:

   ```bash
   touch database/database.sqlite
   ```

   (Windows PowerShell: `New-Item database/database.sqlite`.)

2. **Configure the environment.** Copy `.env.example` to `.env` if you do not
   already have one. The defaults already point at the SQLite file:

   ```env
   DB_CONNECTION=sqlite
   ```

   To use MySQL instead, set `DB_CONNECTION=mysql` together with `DB_HOST`,
   `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`.

   `APP_URL`, `SESSION_DRIVER=file` and `SANCTUM_STATEFUL_DOMAINS` are already
   set for local development on `localhost:8000` and the Vite dev server on
   `localhost:5173`.

   **Email.** `MAIL_MAILER=log` writes every email (password reset,
   verification) to `storage/logs/laravel.log` instead of sending it — search
   the log for `reset-password?` or `verify-email?` to get the link. The links
   are built from `APP_URL`, so it must be the address you open the app from
   (including the `/pacesmonitoring` path). For a real server point `MAIL_*`
   at a transactional provider (Resend, Mailgun, Brevo, SES) and set
   `MAIL_FROM_ADDRESS` to an address on a domain with SPF/DKIM records.

3. **Install PHP dependencies and generate the app key** (skip the key step
   if `.env` already contains one):

   ```bash
   composer install
   php artisan key:generate
   ```

4. **Run the migrations:**

   ```bash
   php artisan migrate
   ```

   The plans are inserted by the migrations; nothing else is seeded. Schools
   register themselves and admins set up their own school years. On a database
   that predates email verification, the migrations also mark every existing
   account as verified.

5. **Install and build the frontend:**

   ```bash
   npm install
   npm run build      # production assets in public/build
   # or, while developing:
   npm run dev        # Vite dev server with hot reload
   ```

6. **Serve the application:**

   ```bash
   php artisan serve
   ```

   Open <http://localhost:8000>, create an account as a school administrator,
   register the school, then sign up teachers with the school code.

## Owner setup

There is no sign-up flow for the owner. Register a normal account, then
promote it:

```bash
php artisan user:make-owner someone@example.com
```

That account's next login lands on the owner dashboard. Owners belong to no
school; they manage every school from there.

## Upgrading an existing (pre-SaaS) installation

Running `php artisan migrate` on a database from before the SaaS layer:

- the former `is_admin` account(s) become **owners**;
- every other user is attached, as a **teacher**, to a new **Default
  Organization** with a `Main` branch and a one-year Starter subscription;
- each teacher's school years are merged into one list per organization
  (same label = same year; grade levels and students are re-pointed), the
  label most teachers had as current becomes the school default, and each
  teacher keeps their own previous current year as their personal pick.

Afterwards, sign in as the owner, open *Organizations → Default
Organization*, rename it, and switch one of its teachers to **Admin** from the
members list so the school has an administrator. The plain-SQL backups taken
right before the migration are kept outside the repository (`private/`,
ignored by git).

## Running the tests

Feature tests run against an in-memory SQLite database (configured in
`phpunit.xml`), so they never touch your SQLite database:

```bash
php artisan test
```

## Project layout

```
app/
  Enums/                   Sex, Quarter, Role, SubscriptionStatus, JoinRequestStatus
  Models/                  User, Organization, Branch, Plan, Subscription, JoinRequest, SchoolYear,
                           GradeLevel, Subject, Student, Pace, PaceAssignment
  Models/Concerns/         OwnedByUser (owner-scoped route-model binding)
  Http/Middleware/         EnsureUserHasRole (`role:admin,owner`), EnsureTenantAccess (`tenant`:
                           membership + read-only when the subscription lapsed)
  Http/Controllers/Api/    Auth, PasswordReset, Verification, Organization (setup + lookup),
                           JoinRequest (teacher side), Profile, SchoolYear, GradeLevel, Subject,
                           Student, Pace, assignments, Dashboard
  Http/Controllers/Api/Admin/   Organization, Branch, JoinRequest, User, Student, GradeLevel,
                           SchoolYear, Pace, Dashboard (organization-scoped; owner may pass ?organization_id=)
  Http/Controllers/Api/Owner/   Dashboard, Organization, Subscription, Plan, User (role)
  Http/Controllers/Api/Concerns/ ResolvesOrganizationScope
  Http/Requests/           Form requests grouped by domain, incl. Auth/ (login throttling, password
                           reset), Profile/, Organization/, JoinRequest/, Owner/
  Providers/AppServiceProvider  API rate limiter; builds the SPA reset / verification links from APP_URL
  Http/Resources/          JSON API resources
  Services/                OrganizationService, JoinRequestService, SubscriptionService, SchoolYearService,
                           GradeLevelService, StudentService, PaceService, PaceAssignmentService, DashboardService
  Services/Admin/          TeacherScope + the Admin*Service analytics, scoped to an organization
  Services/Owner/          OwnerDashboardService
  Console/Commands/        MakeUserOwner (`user:make-owner`)
config/saas.php            Trial length, default plan, expiring-soon window, support email
database/
  migrations/              ... organizations, branches, plans (seeded), subscriptions, join_requests,
                           users tenancy (role / organization / branch / current school year),
                           school years moved to organizations
  factories/               Model factories used by the tests
resources/js/
  core/                    ApiClient (owner scope on /api/admin GETs), ApiError, format helpers, ...
  models/                  User (role helpers), Organization, Branch, Plan, Subscription, JoinRequest, ...
  services/                Auth, Tenant (setup / lookup / join), Admin, Owner, resource services
  stores/                  auth (role + setup state), scope (owner's organization), schoolYears, ...
  components/pages/        Login, Register (role choice), ForgotPassword, ResetPassword, VerifyEmail,
                           Settings (My account), setup/ (OrganizationSetup, JoinOrganization),
                           teacher pages, admin/ (incl. AdminOrganization), owner/ (OwnerHome,
                           OwnerOrganizations, OwnerOrganizationDetail, OwnerPlans)
  router/                  Routes and guards (guest, setup, teacherOnly, requiresAdmin, requiresOwner)
tests/
  Feature/                 AuthTest (incl. login throttling), PasswordResetTest, EmailVerificationTest,
                           TenancyTest (sign-up, join requests, branches, subscriptions, owner),
                           SchoolYearTest, StudentTest, PaceTest, GradeLevelTest, AssignmentTest, AdminTest
```

## API overview

All endpoints live under `/api` and return JSON (`{ data: ... }`). `tenant`
routes need an organization membership; writes additionally need an active
subscription (otherwise `403` with `code: subscription_inactive`).

### Everyone

| Method | Path                               | Purpose |
|--------|------------------------------------|---------|
| POST   | `/register`                        | Sign up (`role` = `admin` or `teacher`); sends the verification email |
| POST   | `/login`, `/logout`                | Session authentication; `/login` answers `429` + `retry_after` after 5 failures per email + IP |
| POST   | `/forgot-password`                 | Email a reset link (`email`); same answer whether or not the address exists |
| POST   | `/reset-password`                  | Set a new password (`token`, `email`, `password`, `password_confirmation`) |
| GET    | `/email/verify/{id}/{hash}`        | Signed link from the verification email (`expires`, `signature`); no session needed |
| POST   | `/email/verification-notification` | Resend the verification email to the signed-in user |
| GET    | `/user`                            | Current user with `email_verified`, organization, branch, subscription and latest join request |
| PUT    | `/profile`, `/password`            | Own name/email (`current_password` required; a new email is un-verified and re-sent a link), own password |
| GET    | `/organizations/lookup?code=`      | A school's name and branches by code |
| POST   | `/organizations`                   | Admin without a school: register it (`name`, `contact_email?`, `contact_phone?`, `branches[]` of `{name, address?, is_main?}`, `plan_id?`) |
| POST   | `/join-requests`                   | Teacher without a school: ask to join (`organization_code`, `branch_id`, `message?`) |
| DELETE | `/join-requests/{id}`              | Withdraw own pending request |
| GET    | `/school-years`, `/school-years/{id}` | The school's years (teacher: own counts and own current flag) |
| POST / PUT | `/school-years`, `/school-years/{id}` | Admin: register / edit (incl. the school default) |
| PUT    | `/school-years/{id}/select`        | Teacher: make it my current year |

### Teacher endpoints (own records only)

| Method     | Path                            | Purpose |
|------------|----------------------------------|---------|
| GET        | `/dashboard/summary`             | Home analytics (`?school_year_id=` or `all`, `?grade_level_id=`, `?subject_id=`) |
| GET/POST   | `/grade-levels`                  | List / create grade levels |
| GET/PUT/DELETE | `/grade-levels/{id}`         | One grade level (with records) / rename, reorder / delete |
| GET/POST   | `/subjects`                      | List (`?grade_level_id=`) / add subjects |
| GET/PUT/DELETE | `/subjects/{id}`             | One subject / rename, reorder / delete |
| CRUD       | `/students`, `/students/{id}`    | Students |
| CRUD       | `/paces`, `/paces/{id}`          | PACEs (`stock?`); `POST /paces/range` registers a number range |
| PATCH      | `/paces/{id}/stock`              | Add or remove booklets (`delta`, never below zero) |
| GET/POST   | `/pace-requests`                 | Own stock requests (`?status=`) / ask (`pace_id?` or `pace_number` + `pace_name`, `quantity`, `needed_by?`, `message?`) |
| PUT/DELETE | `/pace-requests/{id}`            | Edit / withdraw a pending request |
| GET/POST   | `/students/{id}/assignments`     | List / assign PACEs to a student (an `issued_date` takes one booklet per PACE) |
| PUT/DELETE | `/assignments/{id}`              | Update or remove a PACE assignment (issuing / un-issuing moves stock) |

### Admin endpoints (`role:admin,owner`; the owner may add `?organization_id=`)

| Method | Path                              | Purpose |
|--------|------------------------------------|---------|
| GET    | `/admin/dashboard/summary`         | Analytics (`?branch_id=`, `?teacher_id=`, `?school_year_id=`, `?grade_level_id=`, `?subject_id=`) |
| GET/PUT | `/admin/organization`             | The school's details, branches (with student counts), subscription, counts / update details (`admin_password`) |
| GET/POST | `/admin/branches`                | Branches / add one (plan limit) |
| PUT/DELETE | `/admin/branches/{id}`         | Rename, make main / remove (empty, non-main only) |
| GET    | `/admin/join-requests`             | Requests, pending first (`?status=`) |
| POST   | `/admin/join-requests/{id}/accept`, `.../decline` | Decide (`reason?` on decline) |
| GET/POST | `/admin/plan-requests`           | The school's plan requests / ask (`action` = `change` + `plan_id`, or `cancel`; `message?`) |
| DELETE | `/admin/plan-requests/{id}`        | Withdraw the pending request |
| GET    | `/admin/users`                     | Members (`?search=`, `?role=`, `?branch_id=`) |
| GET/PUT | `/admin/users/{id}`               | One member / update name and email (`admin_password`) |
| PUT    | `/admin/users/{id}/password`       | Reset a member's password (`admin_password`), or change your own (`current_password`) |
| PUT    | `/admin/users/{id}/branch`         | Move a member to another branch |
| DELETE | `/admin/users/{id}/membership`     | Remove a teacher from the school |
| GET    | `/admin/students`, `/admin/students/{id}` | Students across the school's teachers |
| GET    | `/admin/grade-levels`              | One teacher's grade levels (`?teacher_id=`, `?school_year_id=`) |
| GET    | `/admin/grade-levels/{id}`, `/admin/subjects/{id}` | One grade level / subject with every record, read-only |
| GET    | `/admin/school-years`              | The school's years (`?teacher_id=` picks that teacher's school) |
| GET    | `/admin/paces`, `/admin/paces/{id}` | PACEs across the school's teachers (with `stock`) |
| GET    | `/admin/pace-requests`             | Teachers' stock requests, pending first (`?status=`, `?teacher_id=`, `?branch_id=`, `?search=`, `?needed_from=` / `?needed_to=`) |
| PUT    | `/admin/pace-requests/{id}`        | Edit a pending request (`pace_number`, `pace_name`, `quantity`, `needed_by`) |
| POST   | `/admin/pace-requests/{id}/give`, `.../decline` | Decide (`quantity?`, `decision_note?`); giving tops up the linked PACE's stock |

### Platform endpoints (`role:owner,vice_admin`; owner-only rows marked)

| Method | Path                              | Purpose |
|--------|------------------------------------|---------|
| GET    | `/plans`                           | (any signed-in user) the active plans a school can pick |
| GET    | `/owner/dashboard/summary`         | Platform totals, subscriptions by status, expiring soon, recent organizations / users, pending join and plan requests |
| GET    | `/owner/organizations`             | Every school (`?search=`, `?status=`, `?trashed=1` to include deactivated ones) |
| GET/PUT | `/owner/organizations/{id}`       | One school with branches, subscription history, members, join and plan requests / update details (`admin_password`) |
| DELETE | `/owner/organizations/{id}`        | Owner only: deactivate (soft delete; `admin_password`) |
| POST   | `/owner/organizations/{id}/restore` | Owner only: restore a deactivated school |
| GET    | `/owner/plan-requests`             | Every school's plan requests (`?status=`, `?organization_id=`) |
| POST   | `/owner/plan-requests/{id}/approve` | Approve: plan change (`starts_at`, `ends_at?`, `notes?`) or cancellation (no body) |
| POST   | `/owner/plan-requests/{id}/decline` | Decline (`decision_note?`) |
| GET/POST | `/owner/organizations/{id}/subscriptions` | Subscription history / record a new period (`plan_id`, `status`, `starts_at`, `ends_at?`, `notes?`) |
| PUT    | `/owner/subscriptions/{id}`        | Change a period's plan, status, dates or notes |
| GET/POST | `/owner/plans`                   | Plans / add one (`max_branches`, `max_teachers?`, `max_students?`, `price_per_year`, ...) |
| PUT    | `/owner/plans/{id}`                | Edit a plan (limits, price, availability) |
| DELETE | `/owner/plans/{id}`                | Owner only: delete a plan with no subscriptions on record (`admin_password`) |
| PUT    | `/owner/users/{id}/role`           | Switch a member between `admin` and `teacher` |
| GET    | `/owner/unassigned-users`          | Accounts in no school, longest waiting first (`?search=`, `?role=teacher|admin`, `?from=` / `?to=` = day created, `YYYY-MM-DD`) |
| DELETE | `/owner/unassigned-users/{id}`     | Delete one (`admin_password`); refused while it owns records |
| GET/POST | `/owner/staff`                   | Owner only: platform accounts / add one (`role` = `owner` or `vice_admin`) |
| PUT    | `/owner/staff/{id}/role`           | Owner only: switch between owner and vice admin (never the last owner) |
| DELETE | `/owner/staff/{id}`                | Owner only: remove a staff account (not your own, never the last owner) |
