# PACES Monitoring System

A web application for tracking students and the PACEs (Packet of Accelerated
Christian Education workbooks) assigned to them per subject, school year and
quarter. Teachers set up their own grade levels and subjects, register
students and PACEs, assign PACEs to a student's subjects, then record when
each PACE was issued, when it was tested, the score achieved and whether the
PACE is a retake. Every list and detail page carries the same visual
language — status bars, layered ring charts and filterable record tables —
and a separate admin role sees the same analytics across every teacher from
its own dashboard.

**Every teacher's records are private to their own account.** Each teacher
sees only their own grade levels, subjects, students, PACEs and PACE records.
Admins are the one exception: they can view and manage every teacher's data
through a dedicated admin area.

## Stack

| Layer     | Technology                                                         |
|-----------|---------------------------------------------------------------------|
| Backend   | PHP 8.2, Laravel 9, Laravel Sanctum (SPA cookie authentication)      |
| Frontend  | Vue 3 (Composition API, `<script setup>`), Pinia, vue-router, Vite  |
| Database  | MySQL (managed locally through phpMyAdmin)                          |
| Styling   | Plain CSS scoped inside each Vue component (minimalist design)      |

The Laravel app and the Vue SPA are served from the same origin, so
authentication uses Sanctum's stateful session cookies.

## Roles

| Role        | What they can do |
|-------------|-------------------|
| **Teacher** | Everything under their own account: school years, grade levels/subjects, students, PACEs and PACE records. Never sees another teacher's data. |
| **Admin**   | A separate, read-mostly area with the same page design as the teacher side: a system-wide Home dashboard (filterable by teacher, school year, grade level and subject), a masterlist of every user (edit details, reset passwords), and a masterlist of every student across every teacher. Can drill into one teacher's grade levels, students and PACEs, and open any student's or PACE's detail page read-only. Does not use the teacher pages (Home, Students, PACEs, Grade levels, School years) — those are hidden and blocked for admin accounts. |

An account becomes an admin via `php artisan user:make-admin {email}` (see
[Admin setup](#admin-setup)); there is no sign-up flow for admins.

## Record statuses and shared charts

Every PACE record is in exactly one of three statuses, and the whole UI uses
the same colours for them:

| Status       | Meaning                                              | Colour |
|--------------|------------------------------------------------------|--------|
| **Assigned** | Assigned to the student but not yet issued           | grey   |
| **Issued**   | Issued (has an issued date) but not yet tested       | blue   |
| **Tested**   | Has a test date or a score                           | green  |

On top of the status, a scored record is a **pass** (80 and above) or a
**fail**, and any record can be flagged as a **retake**.

The same building blocks appear throughout both the teacher and admin areas:

- **PACE records bar** – a compact segmented bar (tested / issued / assigned,
  left to right) with no number beside it; hovering or focusing it shows a
  tooltip with the counts. Lists show a tested / issued / assigned legend next
  to the record count in their toolbar.
- **Layered ring chart** – six concentric rings, outer to inner: assigned,
  issued, tested, pass, retakes, fail. Assigned / issued / tested / retakes
  are shares of every record in scope; pass and fail are shares of the scored
  records (so the two add up to everything scored). The total sits in the
  centre and a legend lists count, denominator and percentage.
- **Average score** – wherever an average is shown it spans *every* record in
  scope: assigned and issued PACEs without a score count as 0, so the figure
  reflects overall progress rather than only the PACEs already tested. Each
  figure carries a hint saying so.
- **Records table** – a searchable table of PACE records with status, school
  year, quarter and subject filters where relevant; the record count and the
  legend sit on the right of the same toolbar row.
- **Icon actions** – table rows use eye (view), pencil (edit) and trash
  (delete) icons with tooltips instead of text buttons; columns have fixed
  widths so they line up across cards and rows.

The top navigation bar stays pinned while the page scrolls; detail pages for
students and users pin their own heading underneath it.

## Features (teacher account)

- **Accounts** – users register and log in; every API route is protected by
  `auth:sanctum`, and every record belongs to the user who created it. A
  record that belongs to someone else answers 404, even when its id is known.
- **Login / Register** – the top navigation bar is hidden on these pages, and
  every password field (login, register, settings, admin password reset) has
  a show/hide eye toggle.
- **Home** – greeting with the Add student / Register PACE actions beside it,
  then a filters row: **school year** (including "All school years",
  defaulting to the current one), **grade level** and **subject** (the
  subject list narrows to the chosen grade level; changing the year clears
  both). Below that:
  - count tiles (Students, PACEs, Grade levels) stacked beside the
    **Progress** layered rings and the **Score distribution** bars, all the
    same height;
  - a getting-started checklist for new accounts;
  - **Top performing students** (average across all their PACEs, plus their
    pass / fail / retake counts, among students with at least 3 scored PACEs)
    beside **Fails and retakes** (PACEs scored below 80 and/or flagged as a
    retake, with an All / Fails / Retakes filter);
  - **PACEs by quarter** as four tiles, each with its total, a status bar and
    its assigned / pass / fail / retake counts;
  - **PACEs by subject** (tested / issued / assigned bars with a combined
    tooltip) beside **Students by grade level** (vertical bars split into
    boys and girls with a tooltip). A card collapses to a single bar under a
    matching filter, so it is hidden while that filter is active.
- **School years** – each user registers the school years they handle
  (`2026-2027`, optional start/end dates) and flags one as current (star
  icon; the current year shows a filled star). The list shows the number of
  grade levels, students and PACE records per year, each linking to the
  matching page filtered to that year. School years can be added, viewed and
  edited (pencil icon) but not deleted.
- **Grade levels and subjects** – each user creates their own grade levels
  (e.g. Kindergarten, Grade 1) **under a school year** and adds the subjects
  taught in each. The page has a search box (grade level or subject name) and
  a school year filter, with the grade level count and legend on the same
  row; cards show each subject's PACE records bar. Levels and subjects can be
  renamed, reordered and deleted; a level that still has students enrolled
  cannot be deleted.
  - **Grade level detail** – school year, student and subject counts, a
    layered ring chart with the average score, a subjects table with bars,
    and a records table (search, subject, status filters) of every record
    under the level.
  - **Subject detail** – grade level and school year, a layered ring chart
    with the average score, and a records table (search, status filter) of
    every record filed under the subject.
- **Students** – master list with search, school-year and grade-level
  filters; add, edit, view and delete students. Columns include a PACE
  records bar and an **average score**, both limited to the school year the
  student is enrolled in. A student has a first, middle and last name, an
  optional extension (Jr., III), birth date, age, sex and grade level (one of
  the user's own). The student's school year is the school year of their
  grade level.
- **PACEs** – master list of registered PACEs (name + number) with search and
  a **With records / Without records** filter; add, edit, view and delete.
  PACE numbers do **not** need to be unique, and a PACE is not tied to any
  particular school year (the same PACE can be assigned across years). PACEs
  can be registered one at a time or as a **range** (e.g. `1001`–`1024`),
  which creates one PACE per number in the range, all sharing the same name —
  leading zeros in the start number are preserved.
- **PACE records** – on a student's page, pick a school year and quarter
  (1st–4th), then assign one or more PACEs per subject. Each assignment
  stores the issued date, date tested, score (0–100) and a **retake** flag
  (a one-click toggle in the table, and a checkbox while editing), and can be
  edited or removed at any time. The issued date starts blank (not defaulted
  to today) so it isn't recorded as issued before it actually is. Auto-assign
  fills the 2nd to 4th quarter from the 1st quarter's PACE numbers.
- **Student detail** – the student's details beside a layered ring chart
  card that follows the school year selected in the PACE Records filter, with
  an "All years" toggle and the average for that scope.
- **PACE Projection** – on a student's page, a table of every subject (rows)
  against the four quarters (columns) for the selected school year, listing
  the PACE numbers assigned in each cell, colored by status to match the PACE
  Records table below it. A retake PACE carries a ↻ mark, and hovering a
  number shows its issued date, tested date, status, score and retake flag.
- **PACE detail** – PACE information beside a records card whose layered
  rings and average follow the **Assignments** table below, which can be
  searched and filtered by subject, status, school year and quarter.
- **Settings** – change your own name, email and password (the password
  change requires your current password). Not shown to admin accounts, who
  manage their own account from Admin → Users instead.
- **Logout** – asks for confirmation before signing out.

### Suggested order for a new teacher account

1. Register the current school year (School years page).
2. Create your grade levels and add subjects to each (Grade levels page).
3. Register the PACEs you issue (PACEs page, one at a time or as a range).
4. Add students, choosing one of your grade levels.
5. Open a student and assign PACEs under each subject for a school year and quarter.

The home page shows this checklist until all four kinds of records exist.

## Features (admin account)

Admins have their own top navigation (**Home**, **Users**, **Students**) in
place of the teacher's tabs. The admin pages share the teacher pages' design
(toolbars, status bars, ring charts, icon actions) but stay read-only apart
from user management.

- **Admin Home** (`/admin`) – the same analytics as the teacher Home, across
  every teacher, narrowed by a filters row: **Teacher**, then that teacher's
  **School year**, **Grade level** and **Subject**. Tiles (Teachers, Students,
  PACEs) sit beside the Progress rings and Score distribution; then Top
  performing students (with the teacher's name) beside Fails and retakes;
  PACEs by quarter; PACEs by subject beside Students by grade level (grade
  levels carry the teacher's name when no teacher is selected); the
  admin-only **Most active teachers** ranking beside **Hardest PACEs** (every
  PACE with a fail or a retake among those with at least 3 scored attempts,
  ranked by fail rate then retake rate, shown as a red fail-rate bar and an
  orange retake-rate bar); and the most recently registered students and
  users. The teacher / admin totals, the recent lists and the teacher ranking
  are always system-wide. There is nothing to add or create here.
- **Users** (`/admin/users`) – masterlist of every registered user (search by
  name/email, Teachers / Admins filter). Opening a user shows a pinned heading
  with their name, role, email and registration date, and tabs:
  - **Profile** – edit the user's name/email, and reset their password
    *without needing their old one*, side by side. Both actions require the
    **admin's own password** to confirm the change — except when an admin
    resets their own password, which skips that re-entry since they are
    already authenticated.
  - **Grade levels** – that teacher's grade levels and subjects, grouped by
    school year, with search, a school year filter and each subject's PACE
    records bar (same information the teacher sees, read-only).
  - **Students** – that teacher's students with the same columns as the
    masterlist (PACE records bar, enrolled-year average), linking to the
    admin student detail page.
  - **PACEs** – that teacher's PACEs with search, a With / Without records
    filter and PACE records bars, linking to the read-only PACE detail page.

  An admin can manage any account this way, including other admins.
- **Students** (`/admin/students`) – masterlist of every student across every
  teacher, with independent filters: search by name, **Teacher**, **School
  year** (depends on the selected teacher) and **Grade level** (depends on
  the selected school year) — the teacher filter only lists teacher accounts,
  not other admins. Columns include the teacher, a PACE records bar and the
  enrolled-year average score. Opening a student shows the same page a
  teacher sees — details beside the ring chart card with its year toggle, the
  PACE Projection table (hover tooltip per PACE number, retake marks), and
  the PACE Records table — all read-only, with the owning teacher linked to
  their user page.
- **PACE detail** (`/admin/paces/{id}`) – the read-only counterpart of the
  teacher's PACE page: information beside the records card, and the filterable
  Assignments table (student, grade, subject, status, school year, quarter).

## Requirements

- PHP 8.2 with the `pdo_mysql` and `pdo_sqlite` extensions
- Composer 2
- Node.js 22 and npm
- MySQL 8 (or MariaDB) reachable on `127.0.0.1:3306`, plus phpMyAdmin

## Setup

1. **Create the database** in phpMyAdmin: open phpMyAdmin, choose *New*, enter
   `paces_monitoring_system` as the database name, pick
   `utf8mb4_unicode_ci` as the collation and click *Create*.

2. **Configure the environment.** Copy `.env.example` to `.env` if you do not
   already have one, then set the database credentials to match your MySQL
   installation:

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=paces_monitoring_system
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   `APP_URL`, `SESSION_DRIVER=file` and `SANCTUM_STATEFUL_DOMAINS` are already
   set for local development on `localhost:8000` and the Vite dev server on
   `localhost:5173`.

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

   Nothing is seeded: each user registers their own school years, grade
   levels and subjects after signing in. When updating an existing
   installation, run the migrations again — the latest one adds the
   `retake` flag to PACE records without touching existing data.

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

   Open <http://localhost:8000>, register an account and log in.

## Admin setup

There is no sign-up flow for admins — promote an existing registered account:

```bash
php artisan user:make-admin someone@example.com
```

That account's next login lands on the admin area instead of the teacher
Home page.

## Running the tests

Feature tests run against an in-memory SQLite database (configured in
`phpunit.xml`), so they never touch your MySQL data:

```bash
php artisan test
```

## Project layout

```
app/
  Enums/                   Sex, Quarter
  Models/                  User, SchoolYear, GradeLevel, Subject, Student, Pace, PaceAssignment
  Models/Concerns/         OwnedByUser (owner-scoped route-model binding)
  Http/Middleware/         EnsureUserIsAdmin (the `admin` route middleware)
  Http/Controllers/Api/    Auth, Profile, SchoolYear, GradeLevel, Subject, Student, Pace,
                           assignments, Dashboard
  Http/Controllers/Api/Admin/  User, Student, GradeLevel, SchoolYear, Pace, Dashboard
  Http/Requests/           Form requests (validation) grouped by domain, incl. Admin/
  Http/Resources/          JSON API resources
  Rules/                   SubjectBelongsToGradeLevel, AssignmentSchoolYear
  Services/                SchoolYearService, GradeLevelService, StudentService, PaceService,
                           PaceAssignmentService, DashboardService (per-quarter / per-subject /
                           per-grade-level / score-band breakdowns shared with the admin dashboard)
  Services/Admin/          AdminUserService, AdminStudentService, AdminGradeLevelService,
                           AdminSchoolYearService, AdminPaceService, AdminDashboardService
  Console/Commands/        MakeUserAdmin (`user:make-admin`)
database/
  migrations/              school_years, grade_levels, subjects, students, paces,
                           pace_assignments, per-user scoping, is_admin, retake flag
  factories/               Model factories used by the tests
resources/js/
  core/                    ApiClient, ApiError, format/schoolYear helpers, recordRings (ring chart data)
  models/                  Front-end domain classes (User, Student, Pace, ...)
  services/                Resource services built on ApiClient, incl. AdminService
  stores/                  Pinia stores (auth, reference, schoolYears, students, paces, notifications)
  components/              templates/, ui/ (AppIcon, modals, fields), charts/ (PaceRecordsBar,
                           PaceRecordsLegend, LayeredRings, QuarterTiles, SexBarChart,
                           StackedBarList, BarList, DualBarList, RecordList), gradeLevels/,
                           students/, paces/, assignments/ (AssignmentTable, PaceRecordsExplorer),
                           pages/ (teacher), pages/admin/ (admin)
  router/                  Routes and auth guards (`requiresAuth`, `requiresAdmin`, `teacherOnly`)
tests/
  Feature/                 AuthTest, StudentTest, PaceTest, GradeLevelTest, SchoolYearTest,
                           AssignmentTest, AdminTest, ...
```

## API overview

All endpoints live under `/api` and return JSON (`{ data: ... }`).

### Teacher endpoints (own records only)

| Method     | Path                            | Purpose                                                        |
|------------|----------------------------------|-----------------------------------------------------------------|
| POST       | `/register`, `/login`, `/logout` | Authentication (session based)                                  |
| GET        | `/user`                          | Current user                                                    |
| PUT        | `/profile`, `/password`          | Update own name/email, change own password                      |
| GET        | `/dashboard/summary`             | Home analytics (`?school_year_id=` or `all`, `?grade_level_id=`, `?subject_id=`) |
| GET/POST   | `/school-years`                  | List / register the user's school years (with grade level, student and record counts) |
| GET/PUT    | `/school-years/{id}`             | View or edit a school year (set current)                       |
| GET/POST   | `/grade-levels`                  | List / create the user's grade levels                          |
| GET        | `/grade-levels/{id}`             | One grade level with its subjects, status counts and every record under it |
| PUT/DELETE | `/grade-levels/{id}`             | Rename, reorder or delete a grade level                         |
| GET/POST   | `/subjects`                      | List (`?grade_level_id=`) / add subjects                       |
| GET        | `/subjects/{id}`                 | One subject with its grade level, status counts and every record under it |
| PUT/DELETE | `/subjects/{id}`                 | Rename, reorder or delete a subject                             |
| CRUD       | `/students`, `/students/{id}`    | Students (the list carries enrolled-year status counts and average) |
| CRUD       | `/paces`, `/paces/{id}`          | PACEs (the list carries status counts; the detail every assignment) |
| POST       | `/paces/range`                   | Register a range of PACE numbers sharing one name               |
| GET/POST   | `/students/{id}/assignments`     | List / assign PACEs to a student                                |
| PUT/DELETE | `/assignments/{id}`              | Update (dates, score, remarks, `retake`) or remove a PACE assignment |

### Admin endpoints (`admin` middleware, every teacher's records)

| Method | Path                              | Purpose                                                              |
|--------|------------------------------------|------------------------------------------------------------------------|
| GET    | `/admin/dashboard/summary`         | System-wide analytics (`?teacher_id=`, `?school_year_id=`, `?grade_level_id=`, `?subject_id=`), plus totals, recent students/users, per-school-year breakdown, teacher activity and hardest PACEs |
| GET    | `/admin/users`                     | Masterlist of every user (`?search=`)                                  |
| GET    | `/admin/users/{id}`                | One user's details (no password)                                      |
| PUT    | `/admin/users/{id}`                | Update name/email (`admin_password` required)                         |
| PUT    | `/admin/users/{id}/password`       | Reset the user's password (`admin_password` required unless it's self) |
| GET    | `/admin/students`                  | Masterlist across every teacher (`?search=&teacher_id=&grade_level_id=&school_year_id=`), with enrolled-year status counts and average |
| GET    | `/admin/students/{id}`             | One student's full detail + their teacher's school years              |
| GET    | `/admin/grade-levels`              | One teacher's grade levels (`?teacher_id=` required, `?school_year_id=` optional) |
| GET    | `/admin/school-years`              | One teacher's school years (`?teacher_id=` required)                   |
| GET    | `/admin/paces`                     | PACEs (`?search=&teacher_id=`)                                         |
| GET    | `/admin/paces/{id}`                | One PACE's full detail                                                 |
