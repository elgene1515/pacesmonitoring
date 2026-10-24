# PACES Monitoring System

A multi-tenant (SaaS) web application for tracking students and the PACEs
(Packet of Accelerated Christian Education workbooks) assigned to them per
subject, school year and quarter. Each **school (organization)** registers
itself, adds its **branches**, admits its **teachers**, and sets up the school
years everyone works in. The school's admin builds each branch's **grade
levels** (with their sections and subjects), decides **who teaches what**,
keeps the branch's **PACE catalog and booklet stock** and enrolls students.
Teachers work with the students of the classes they teach: they assign PACEs
to a student's subjects, then record when each PACE was issued, when it was
tested, the score achieved and whether it is a retake. The school's admins see
the analytics across every teacher of the school, and the **platform owner**
manages every school, its plan and its subscription from a separate
dashboard.

**How a school is structured** (the design is in `private/DOMAIN_REDESIGN.md`):

- A **grade level** belongs to a branch and a school year, with its
  **subjects** and its **sections**. A section offers some of the grade
  level's subjects (Section 1 may have English, Math and Filipino while
  Section 2 has only English and Math).
- A **student** is enrolled in one section and takes their own subjects from
  the ones it offers, so students of one section may differ. Students are
  shared, not owned by whoever registered them.
- A **teacher** works through **teaching assignments** (one teacher per
  section and subject, set by the admin or requested by the teacher). A
  teacher sees a student only when the student takes a subject they teach in
  the section, and can assign and edit PACE records only in those subjects;
  other records are visible but read-only. Teachers cannot edit a student's
  details, they propose changes the admin applies.
- **PACE stock is a ledger.** The PACE catalog is per branch. The admin
  receives booklets into the branch pool and gives them to teachers; issuing
  a PACE uses one of the teacher's own booklets, and every movement is logged.

Schools never see each other.

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
`organization_id` (school years) or belongs, through its branch, to an
organization (grade levels, sections, students, PACEs, PACE records). Nothing
in the local setup changes because of it.

## Roles

Display names: the platform owner shows as **CEO**, platform staff as
**Admin**, a school's administrator as **School Admin**. Platform accounts
list "PACES Monitoring System · Management" where members list their school
and branch.

| Role        | How they get the role | What they can do |
|-------------|-----------------------|------------------|
| **Teacher** | Signs up as a teacher, enters a school code, picks a branch and asks to join; a school admin accepts. | Works with the classes they teach: sees their sections and students, enrolls students into a section they teach, proposes corrections to a student's details, asks for open classes and for PACE booklets, and assigns PACEs and records results in the subjects they teach. Picks their own *current* school year from the school's list. Never sees classes they are not assigned to. |
| **Admin**   | Signs up as a school administrator and registers the school (name, contact details, branches; the first branch is the main one) — or, for a school that is already registered, enters its code and asks to join as an administrator (a current admin accepts, within the plan's admin limit). | Their school only: edit school details, add/rename/remove branches (within the plan's limit), accept or decline join requests (choosing the role for administrator requests), make a member an administrator or a teacher (never themselves, never the last admin), move teachers between branches or remove them, reset their passwords, register school years and set the school default, build the **grade levels, sections and subjects** and assign the teachers, **enroll, edit, move and delete students**, decide the teachers' class and student-change requests, keep the **PACE catalogs and booklet stock**, and analytics across every teacher of the school. |
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
   the free 30-day trial, or one of the paid plans. The branches named here must fit the
   chosen plan's branch limit (the free trial counts as the Starter plan: one branch).
   The school gets a code such as `SCH-7F3K2A` and starts on
   the trial either way; a paid plan chosen here becomes a **plan request**
   the owner approves (which records the subscription) or declines.
   **Teacher:** *Join your school* – type the school code, pick a branch,
   optionally add a message, send the request. The page shows the pending
   request (it can be withdrawn) until an admin decides; a declined request
   shows the reason and a new one can be sent.
   **A second administrator** does not register another school: on the
   *Register your school* step they follow *Enter its code and ask to join as
   an administrator*, which is the same join page flagged for the
   administrator role.
3. Admins see pending requests as a badge on the **Users** tab and accept
   or decline them at the bottom of that page. For a request marked *Wants
   to join as administrator*, Accept asks whether to admit them as an
   **administrator** (greyed out when the plan's admin limit is reached) or
   as a **teacher**. An accepted member lands on their Home page the next
   time they sign in (or press *Check again*).

### School years

School years belong to the school: admins register them (`2026-2027`,
optional start/end dates) and star one as the **school default**. Every
teacher picks from the same list and stars the year they are working in
(their *current* year); new teachers start on the school default. The pick is
personal, so one teacher can still close out last year while the rest of the
school has moved on. Grade levels, students and PACE records are filed under school
years, and the Home page totals follow the teacher's current year.

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
- **Sign-up** asks for the role, then the name in parts (**first name**,
  optional **middle name**, **surname**, optional **suffix** such as Jr. / III),
  a **contact number**, an optional **address**, email and password, and the
  person must tick *I agree to the **Terms of Service** and **Privacy
  Policy*** (each opens in a **dialog** over the form, with a link to the
  other one; there are no separate pages, and My account opens the same
  dialog from its Terms / Privacy links). The wording is **temporary
  placeholder text** that lives in one file, `resources/js/core/legal.js`:
  replace it with the final, legally reviewed text and set
  `LEGAL_IS_TEMPORARY` to false to drop the "temporary" note. The full
  name shown everywhere is composed from the parts, and the acceptance is
  stored with the account (`terms_accepted_at`, `terms_version` from
  `SAAS_TERMS_VERSION` / `config/saas.php`; bump it, and `LEGAL_VERSION` /
  `LEGAL_UPDATED` in `legal.js`, when the texts change). Accounts made earlier
  have only a single name and no recorded acceptance; My account offers them
  the split name as a starting point to check.
- **My account** (click your name in the account menu; `/settings`, every
  role): your details as an admin would see them (including contact number,
  address and when you accepted the terms), plus the forms to change name (in
  parts) / contact details / email and the password. Both forms require your
  **current password**.
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

Teacher navigation: **Home · Students · PACEs · My classes · School years**.

- **Home** – greeting and the *Enroll student* / *Request Stock* buttons (the
  latter opens the same dialog as on the PACEs page),
  filters (school year, grade level, section, subject, PACE; the section
  filter offers your sections of the chosen grade level, the subject filter
  the subjects you teach there). **Every figure follows every filter**: the
  count tiles, Progress rings, Score distribution, Top performing students,
  Fails and retakes, PACEs by quarter / subject and Students by grade level
  (always shown: with a grade level or section filter it is a single bar,
  boys and girls, labelled with the section when one is picked).
  A subject keeps the students who take it and the sections you teach it in; a
  PACE keeps the students who have it (and only that PACE in the PACE count);
  with a class or subject filter the PACE count is the PACEs used in those
  records. Count tiles (students, PACEs,
  classes) sit beside the Progress rings and Score distribution, a getting-started
  checklist, Top performing students, Fails and retakes, PACEs by quarter,
  PACEs by subject and Students by grade level.
  The greeting, action buttons and filters stay pinned under the top bar while
  you scroll (on phones only the greeting and buttons stay, so the stacked
  filters do not cover the page).
- **School years** – the school's list with the teacher's own counts; star
  the year you are working in. (Admins register the years.)
- **My classes** (`/classes`) – the grade levels and sections you teach, with
  the subjects you handle in each and who teaches the rest. A **section**
  page (`/classes/sections/{id}`) lists its subjects with their teachers
  (open ones have a *Request* button) and the students you teach there, with
  their student ID, the subjects each takes and their average score. The
  student list has the same filters as the Students page — search by name or
  student ID (part of either), sex and average-score range — plus a *subject
  taken* filter. Grade level and subject pages show the rings and
  the filterable records (subject, school year, quarter, status, score range)
  of your students; their titles stay pinned under the top bar.
- **Class requests** (`/classes/requests`) – your requests for open classes
  (a subject a section offers that nobody teaches yet) with their status, and
  the branch's classes with a *Request* button. Pending requests can be
  withdrawn; a declined one shows the admin's reason.
- **Students** – the students you teach, with their student ID in the first
  column, search by name or student ID (part of an ID works) and filters
  (school year, section, sex, average-score range; records without a score drop
  out once a bound is set). *Enroll student* adds a student to one of your
  sections and ticks the subjects they take (the section's offered subjects;
  keep at least one you teach). Teachers cannot edit or delete a student:
  *Propose changes* sends corrections (name, extension, birth date, sex, and
  the ID number, contact, emergency and health details) to
  the admin, one pending proposal per student (**Proposed changes**,
  `/students/changes`, lists them with the admin's decision). The detail page
  has the ring chart, the PACE Projection table and the PACE Records table;
  *Assign PACEs* and the inline edit tools exist only for subjects you teach,
  the others show "Taught by …" and stay read-only.
- **PACEs** – the branch's PACE catalog, read-only: **your stock** (the
  booklets you hold) next to the branch **pool**, filterable by what you hold.
  Issuing a PACE uses one of your booklets; clearing the issued date or
  deleting the record gives it back. A PACE you hold none of can still be
  assigned, but not issued. A PACE's page lists its assignments (student ID
  before the name; search by name or student ID), filterable by
  subject, school year, quarter, status, score range and issued / tested date
  ranges (a record without that date drops out once a bound is set); the
  records chart and average follow the filters.
- **Stock requests** (`/paces/requests`) – ask the school admin for booklets
  with **Request Stock** (on the PACEs page and on this page): tick any number
  of catalog PACEs, each with its quantity, add PACEs that are not in the
  catalog yet by number and name, and set one needed-by date and note; one
  request is filed per PACE. Pending requests can be edited or
  withdrawn; decided ones stay as history with the admin's note. Filter by
  status and by the date range it was requested in. Teachers cannot top up or
  edit stock themselves.
- **PACE records** – assign PACEs per subject, school year and quarter (only
  under a subject the student takes and you teach); record issued date, date
  tested, score and the retake flag. Auto-assign fills quarters 2–4 from
  quarter 1 for your subjects. On the student's page, the name and eye icon of
  each subject you teach open that subject's page (other teachers' subjects
  stay plain text).
- **My account** – your own details, change your name, email and password
  (see *Accounts and security*).

## Account menu and theme (every account)

The member's name in the top bar opens an account menu: who is signed in (the name / email row opens **My account**), the **theme** and **Sign out**. A school admin also sees the school's **plan** (under their name in the bar and in the menu, with its status) and the **school code** with a **Copy** button for handing out to teachers. On small screens the same menu sits at the bottom of the hamburger panel.

The theme is **Light**, **Dark** or **System default** (follows the device and changes live with it). The choice is remembered per browser in `localStorage` (`paces.theme`) and applied before the page paints, so there is no flash. Colours are CSS variables in `resources/css/app.css` (`:root` for light, `:root[data-theme="dark"]` for dark); new components should use the variables instead of literal colours.

## Features (admin account)

Admin navigation: **Home · Students · PACEs · Grade Levels · School Years ·
Users · Organization**, with badges for pending student-change proposals
(Students), stock requests (PACEs), class requests (Grade Levels) and join
requests (Organization). School admins edit their own details from **My
account** (account menu) or from their own page under Users; either way the
current password is required.

- **Home** – the analytics across every teacher of the school, with Branch,
  Teacher, School year, Grade level, Section, Subject and PACE filters (the
  grade level filter needs a school year or teacher picked, the section filter
  a grade level). **Every figure follows every filter**: the Teachers,
  Students and PACEs tiles, the Progress rings, Score distribution, Top
  students, Fails and retakes, PACEs by quarter / subject, Students by grade
  level (always shown; one bar, labelled with the section, when you narrow
  down), Most active teachers and Hardest PACEs. A school year or class filter
  counts the teachers who teach in what is left; a subject keeps the students
  who take it and the sections that offer it (or that the picked teacher
  teaches it in); a PACE keeps the students who have it (and only that PACE in
  the PACE count); with a class or subject filter the PACE count is the PACEs
  used in those records. The heading
  and filters stay pinned under the top bar while you scroll (on phones only
  the heading stays). A school admin's recent students and recent members sit
  at the bottom of the **Organization** page instead of on Home (the platform
  owner's Analytics page keeps them).
- **Students** – every student of the school, with the student ID in the
  first column and a search by name or student ID (part of either), filterable
  by branch, teacher,
  school year, grade level, section, sex and an average-score range (picking a
  branch narrows the teacher list; the branch filter appears once the school
  has more than one). *Enroll student* (for the platform owner the form starts
  with a **school dropdown you can type into** and a **branch** dropdown that
  narrows the sections; a school admin whose school has several branches gets
  the same **branch** dropdown), edit, **move to another section** and
  delete (with the student's PACE records). The form has a checklist of the
  subjects the chosen section offers; dropping a subject that has PACE records
  is refused, moving to another section keeps the subjects it also offers
  (another grade level: everything it offers). The student's page (its heading line adds the school after the branch for the platform owner) names their
  section, branch, teachers, the subjects taken and who registered them, a
  **Contact, emergency and health** card (open by default; folded, its heading
  still flags a medical note and shows the emergency phone), and
  an **Enrollment history** card (folded by default; its heading names the
  current class). Both fold and unfold from their heading, and each browser
  remembers the choice. The history lists every section the student has been in
  (school year, grade level, section, from–to), written automatically on
  enrollment and on every move; the names are kept even after the section is
  deleted. PACE records never move: they stay under the school year and
  subject they were made in, and are reachable from the year selector.
  **Student details** (all optional) on the enrollment / edit form: the
  school's own **student ID number** (unique within the school; the student
  lists search it too), student email and mobile number, address, an
  **emergency contact** (name, relationship, phone, email), **blood type**
  and remarks on **known allergies or medical conditions** (highlighted on
  the student page). Teachers who enroll fill them in too; later changes go
  through *Propose changes*.
  **Proposed changes** (`/admin/students/changes`) is the inbox of teachers'
  corrections: *Apply* updates the record, *Decline* takes a reason.
- **PACEs** – each branch's catalog with its **pool** (booklets on hand) and
  the booklets held by teachers, searchable and filterable by branch, pool
  level and whether the PACE has student records. The platform owner, while
  no school is picked in the scope bar, also gets a **school** filter and a
  **School** column before Branch (the branch filter then lists that school's
  branches). *Register PACE* adds one PACE or a number range with the booklets
  received. Row actions: **receive** booklets, **give** booklets to a teacher
  (from the pool), edit, delete. **Select** rows (or all listed) for a batch
  change of the pool: *Booklets arrived* (add), *Add or remove* a number
  (negative removes), or *Set the pool to…* a counted number; a preview shows
  every pool before and after and nothing is saved if any would go below zero.
  A PACE's page (`/admin/paces/{id}`, headed *School · #number · name* for the
  platform owner) shows the pool, the teachers' booklets
  (each adjustable), the **stock ledger** (received, given, returned,
  corrections, with who and why), *Correct pool count* (type the right count
  after a miscount) and the records of the PACE.
  **Stock requests** (`/admin/paces/requests`, badge with the pending count)
  lists the teachers' requests: filter by status, branch and teacher, search
  by teacher or PACE, narrow by a "needed by" range; edit a pending request,
  **give** it (moves the quantity from the pool to the teacher; the pool must
  have enough; a PACE not in the catalog yet is added, and a quantity of 0
  only adds it) or decline it with a note. **Several at once:** tick the
  pending rows (or the header box for all of them) and use *Give selected* or
  *Decline selected*. Giving shows a preview first: each request gets the
  quantity asked for, oldest first, against the pool of its PACE after the
  ones before it, with one optional note for all; a request the pool cannot
  cover, one whose PACE is not in the catalog yet (give those one by one) or
  one decided meanwhile stays pending, is listed afterwards with the reason
  and stays ticked. Declining several takes one shared note.
- **Grade Levels** (`/admin/grade-levels`) – per branch and school year: grade
  levels with their subjects and sections, how many classes still lack a
  teacher, add / rename / reorder / delete (the platform owner gets a **school
  filter you can type into** before the branch filter, and the *Add grade level*
  form starts with the same school dropdown; the branches and school years
  offered are that school's). Deleting a grade level or section
  is refused while students are enrolled. A grade level's page has the
  **section × subject grid**: a dropdown per cell picks the teacher, or
  *Not offered* when that section does not have the subject (refused while a
  teacher is assigned or students take it). Subjects and sections are added,
  renamed and deleted there; a new section offers every subject and a new
  subject is offered by every section until you take it off. **Class
  requests** (teachers asking for an open class) are at the top of the page:
  *Accept* assigns the teacher, *Decline* takes a reason. Section pages (headed
  *School · Grade level · Section* for the platform owner, who also sees
  *School · Grade level* on a grade level's page, *School · Grade level ·
  Subject* on a subject's page, and the school at the start of each group on
  the list) show
  the subjects with their teachers and the roster with each student's
  subjects; subject pages list their teachers per section and their records.
  Every PACE records table (PACE, subject and grade level pages, admin and
  teacher) shows the student ID before the name, and its search matches the
  student ID (or part of it) as well as the name.
  **Promote students** on a section's page is the year-end step: pick the
  school year and the section everyone continues in (the next year and the
  next grade level are suggested), change single students (a repeater goes to
  the same grade level's section; *Stays* leaves a student who left the school
  where they are), and move them all in one go. Subjects follow the new
  section, records and history are kept, the old (now empty) section can be
  deleted.
- **School Years** – register and edit the school's years; star the default.
- **Users** – every member of the school (search, role and branch filters; the platform owner also gets an
  **organization filter you can type into** and a **registered date range**),
  and at the bottom the **join requests**: teachers and would-be
  administrators who entered the school code, with a two-line message
  preview, a dialog with the whole request (name, email, branch, requested
  role, dates, status, message), and accept / decline (with a reason) as
  icons; an administrator request asks *Accept as administrator / teacher*
  (administrator only within the plan's admin limit). The requests have a
  search (name and email) and a status filter (Pending by
  default, All, Accepted, Declined); the platform owner also sees a **School**
  column before Branch, and sees the requests of every school here (narrowed
  by the organization filter or the scope bar) and can decide them.
  A member's page shows their school and branch and whether their email is
  verified, edits their details, resets their password (with the admin's own
  password as confirmation), moves them to another branch or removes them from
  the school, and for teachers shows the classes they teach, their students
  and the PACE booklets they hold. The same page serves the platform owner.
- **Organization** – school details (name and contact details, confirmed with
  the admin's password; the address is read from the main branch) and code
  (copy button), the subscription card (plan, status, period, branches /
  teachers / students used against the limits, with *Choose / Change plan*
  and *Cancel subscription* requests to the provider at the bottom and their
  outcome), branches (add, rename, set the address, make main, remove; with
  teacher and student counts).

## Features (owner account)

Owner navigation: **Home · Organizations · Analytics · Users · Grade Levels · Students · PACEs · Plans · Staff**
(Staff is owner-only; vice admins see the rest). The tabs carry **badges** with what
waits across every school: Organizations (join + plan requests), Users (join
requests), Grade Levels (class requests), Students (proposed changes) and
PACEs (stock requests); the counts come with `/api/user` (`pending_counts`,
platform accounts only, active schools only) and are asked again on each page
change. Platform accounts edit their
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
  An account that still has records from a previous school (school years,
  PACE stock requests and the like) cannot be deleted.
- **Organizations** – every school with plan, status, branches, teachers,
  pending requests and end date (search, status, plan and an "ends" date range
  filter, *Show deactivated*; schools without an end date drop out once a date
  bound is set).
  A school's page (its heading and buttons stay pinned under the top bar while
  scrolling) edits its details (owner password required), shows and
  manages its subscription (assign a plan, renew, edit a period, quick status
  changes, full history), decides the school's plan requests, lists its
  branches, members (with a role switch between admin and teacher; search by
  name or email, branch and role dropdowns and a registered date range) and
  join requests (the same panel as the Users page: search, status filter, a
  School column, and the owner can accept or decline them), jumps into the admin area narrowed to that school, and (owner
  only) deactivates or restores the school.
- **Analytics / Users / Grade Levels / Students / PACEs** – the admin pages, either platform-wide or
  narrowed to the school picked from Organizations (a scope bar under the top
  bar says which, and clears the selection). In a student's PACE records the
  PACE numbers open the read-only admin PACE page.
  On the owner's **Home**, *Plan requests waiting for you* has an organization-name search and a
  sent-date range (it lists every pending request), and *Join requests* is the
  Users page's container for every school at once with the search also finding a
  school by name plus a branch dropdown; the owner can decide them there.
  Without the scope bar, the Analytics, Users and Students pages let the owner pick a
  school from their own filter (Analytics and Users then offer that school's
  branches too; its branch filter stays disabled until a school is picked,
  since branch names repeat across schools). The school filters are dropdowns
  you can type into. On Analytics, with every organization at once, each
  branch shown (grade level, subject, teacher and hardest-PACE rows, the
  teacher, school year and grade level options, the recent lists) is followed by
  the organization it belongs to; every figure and chart follows the filters.
- **Plans** – add or edit tiers (branch, teacher and student limits, price),
  retire or restore them, and (owner only) delete a plan nobody has ever
  subscribed to.
- **Staff** (owner only) – add platform accounts as owner or vice admin with
  the same details a sign-up asks for: first name, optional middle name,
  surname, optional suffix, contact number, optional address, email and a
  first password (no terms checkbox: the owner creates the account, so none is
  recorded). The list shows each person's contact number. Switch their role,
  remove them. There is always at least one owner.

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

   The plans are inserted by the migrations; a real school registers itself
   and its admin sets up the school years, grade levels and PACEs. On a
   database that predates email verification, the migrations also mark every
   existing account as verified.

5. **(Optional) Seed a demo school** for development and demos, see
   *Demo data* below.

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

### Upgrading to the sections / branch-scoped model (2026-10)

The migrations `2026_10_21_000001` to `000007` replace the teacher-owned
grade levels, students and PACEs with the structure described above (sections,
teaching assignments, student change requests, branch PACE catalogs and the
stock ledger). They assume a development database: run
`php artisan migrate:fresh` (this wipes every account) and then
`php artisan user:make-owner {email}` again. A database that already ran them
only needs `php artisan migrate` for the later ones:

- `2026_10_22_000001_create_student_subjects_table` – each student's subjects
  (existing students take every subject of their grade level);
- `2026_10_22_000002_create_section_subjects_table` – the subjects each section
  offers (existing sections offer every subject of their grade level);
- `2026_10_23_000001_add_requested_role_to_join_requests_table` – join
  requests may ask for the administrator role;
- `2026_10_23_000002_create_student_enrollments_table` – the enrollment
  history (existing students get an open row for their current section);
- `2026_10_23_000003_add_contact_and_health_details_to_students_table` – the
  optional student details below (existing students start with them empty);
- `2026_10_23_000004_add_personal_details_and_terms_to_users_table` – the
  name parts, contact number, address and terms acceptance of each account
  (existing accounts keep their single name and start with the rest empty).

A fresh database starts empty: the school admin sets up the grade levels
under **Grade Levels**, or seed the demo school (next section).

## Demo data

`php artisan db:seed` (any environment except production, where it does
nothing) builds a complete demo school on a freshly migrated database; the
same names, dates and scores come out every time. It can also be run in
parts, and each part skips what already exists, so running it twice is
harmless:

| Seeder | Creates |
|--------|---------|
| `DemoSchoolSeeder` | the platform owner, **Grace Christian Academy** (Growth plan, branches Main Campus and North Campus), its admin and six teachers (names in parts — a middle name, a suffix —, contact numbers, some addresses, the Terms and Privacy Policy accepted), the school years 2025-2026, **2026-2027 (current)** and 2027-2028, two pending join requests (a teacher, and an **administrator** asking to be one), and a second small school on the free trial |
| `DemoGradeLevelsSeeder` | the grade levels, subjects and sections of both branches, the subjects each section offers (Grade 1 Section 2 has no Filipino), the teaching assignments (some classes left open) and a pending class request; plus **2027-2028's** Main Campus classes (Grades 1–4, no teachers, no students) ready for the year-end *Promote students* |
| `DemoStudentsSeeder` | 70 students, each taking the subjects their section offers (some skip Filipino or Science), with ID numbers `2026-0001`…, an address and an emergency contact; some have a student email or mobile number, a blood type or an allergy on record. Everyone was enrolled when the year started (1 June 2026) and two Grade 1 children moved from Section 2 to Section 1 in August, so their **enrollment history** has two stays |
| `DemoPacesSeeder` | each branch's PACE catalog with booklets in the pool (a low and an empty pool), booklets given to the teachers, and stock requests: pending, given and declined |
| `DemoRecordsSeeder` | first-quarter PACE records in every state (assigned, issued, tested, retakes), some second-quarter assignments, and student change requests: pending, applied and declined (one pending proposal changes an emergency phone and a health remark) |

`DemoSeeder` runs them in that order. Every account's password is
`password`:

| Account | Role |
|---------|------|
| `owner@example.com` | platform owner (CEO) |
| `admin@example.com` | School Admin of Grace Christian Academy |
| `ana@`, `ben@`, `carla@`, `diego@example.com` | teachers, Main Campus |
| `elena@`, `felix@example.com` | teachers, North Campus |
| `sunrise.admin@example.com`, `tomas@example.com` | School Admin and teacher of Sunrise Learning Center (free trial) |
| `gina@example.com` | a teacher with no school yet, her join request pending |
| `hannah@example.com` | an administrator with no school yet, asking to join as an **administrator** (the admin chooses the role on accepting) |

To try the year-end step: sign in as the admin, open *Grade Levels* → Grade 1 →
Section 1 → *Promote students*; 2027-2028 and Grade 2 · Section A are suggested.

To start over: `php artisan migrate:fresh --seed`.

## Running the tests

Feature tests run against an in-memory SQLite database (configured in
`phpunit.xml`), so they never touch your SQLite database:

```bash
php artisan test
```

## Project layout

```
app/
  Enums/                   Sex, Quarter, Role, SubscriptionStatus, JoinRequestStatus, PaceRequestStatus,
                           TeachingAssignmentRequestStatus, StudentChangeRequestStatus, PaceStockMovementType
  Models/                  User, Organization, Branch, Plan, Subscription, JoinRequest, SchoolYear,
                           GradeLevel, Section, Subject, TeachingAssignment, TeachingAssignmentRequest,
                           Student, StudentChangeRequest, Pace, PaceStockHolding, PaceStockMovement,
                           PaceAssignment, PaceRequest
  Models/Concerns/         OwnedByUser (`ownedBy` = what a teacher's teaching assignments cover; route-model
                           binding for teachers is limited to it)
  Http/Middleware/         EnsureUserHasRole (`role:admin,owner`), EnsureTenantAccess (`tenant`:
                           membership + read-only when the subscription lapsed)
  Http/Controllers/Api/    Auth, PasswordReset, Verification, Organization (setup + lookup),
                           JoinRequest (teacher side), Profile, SchoolYear, GradeLevel, Subject, Section,
                           TeachingAssignment (requests), Student, StudentChangeRequest, Pace, PaceRequest,
                           assignments, Dashboard
  Http/Controllers/Api/Admin/   Organization, Branch, JoinRequest, User, Student, StudentChangeRequest,
                           GradeLevel, Subject, Section (+ teaching assignments, offered subjects),
                           TeachingAssignmentRequest, SchoolYear, Pace (catalog + stock), PaceRequest,
                           Dashboard (organization-scoped; owner may pass ?organization_id=)
  Http/Controllers/Api/Owner/   Dashboard, Organization, Subscription, Plan, User (role)
  Http/Controllers/Api/Concerns/ ResolvesOrganizationScope
  Http/Requests/           Form requests grouped by domain, incl. Auth/ (login throttling, password
                           reset), Profile/, Organization/, JoinRequest/, Owner/, Student/, Pace/ (stock)
  Rules/                   TeacherTeachesSubjectInSection, StudentTakesSubject, AssignmentSchoolYear
  Providers/AppServiceProvider  API rate limiter; builds the SPA reset / verification links from APP_URL
  Http/Resources/          JSON API resources
  Services/                OrganizationService, JoinRequestService, SubscriptionService, SchoolYearService,
                           GradeLevelService, TeachingAssignmentService, StudentService,
                           StudentChangeRequestService, PaceService, PaceStockService, PaceRequestService,
                           PaceAssignmentService, DashboardService
  Services/Admin/          TeacherScope + the Admin*Service area (grade levels, students, paces, dashboard,
                           users), scoped to an organization
  Services/Owner/          OwnerDashboardService
  Console/Commands/        MakeUserOwner (`user:make-owner`)
config/saas.php            Trial length, default plan, expiring-soon window, support email
database/
  migrations/              ... organizations, branches, plans (seeded), subscriptions, join_requests,
                           users tenancy, school years moved to organizations, then the 2026-10 model:
                           sections, branch grade levels, teaching assignments, students in sections,
                           student change requests, branch PACE catalogs, the stock ledger,
                           student_subjects and section_subjects
  factories/               Model factories used by the tests and the demo seeders
  seeders/                 DatabaseSeeder, DemoSeeder and the Demo*Seeder parts (see *Demo data*)
resources/js/
  core/                    ApiClient (owner scope on /api/admin GETs), ApiError, format helpers, ...
  models/                  User (role helpers), Organization, Branch, Plan, Subscription, GradeLevel, Section,
                           Subject, TeachingAssignment(+Request), Student, StudentChangeRequest, Pace,
                           PaceStockMovement, PaceRequest, ...
  services/                Auth, Tenant (setup / lookup / join), Reference + Teaching + Student + Pace
                           (teacher side), Admin, Owner
  stores/                  auth (role + setup state), scope (owner's organization), schoolYears, reference
                           (the teacher's classes), students, paces, teaching, studentChangeRequests, ...
  components/pages/        Login, Register (role choice), ForgotPassword, ResetPassword, VerifyEmail,
                           Settings (My account), setup/ (OrganizationSetup, JoinOrganization),
                           teacher pages (Home, GradeLevels = My classes, TeachingRequests, SectionDetail,
                           Students, StudentChangeRequests, Paces, ...), admin/ (AdminGradeLevels,
                           AdminStudents, AdminPaces, AdminPaceRequests, AdminStudentChangeRequests,
                           AdminOrganization, ...), owner/ (OwnerHome, OwnerOrganizations,
                           OwnerOrganizationDetail, OwnerPlans)
  router/                  Routes and guards (guest, setup, teacherOnly, requiresAdmin, requiresOwner)
tests/
  Feature/                 AuthTest (incl. login throttling), PasswordResetTest, EmailVerificationTest,
                           TenancyTest (sign-up, branch limits, join requests, branches, subscriptions,
                           owner), SchoolYearTest, GradeLevelTest, TeachingAssignmentTest, StudentTest,
                           StudentSubjectsTest, SectionSubjectsTest, PaceTest, PaceStockTest, BatchStockTest,
                           PaceRequestTest, AssignmentTest, AdminTest, AdminScopeTest, PlatformTest,
                           DemoSeederTest
```
## API overview

All endpoints live under `/api` and return JSON (`{ data: ... }`). `tenant`
routes need an organization membership; writes additionally need an active
subscription (otherwise `403` with `code: subscription_inactive`).

### Everyone

| Method | Path                               | Purpose |
|--------|------------------------------------|---------|
| POST   | `/register`                        | Sign up (`role` = `admin` or `teacher`, `first_name`, `middle_name?`, `last_name`, `suffix?`, `phone`, `address?`, `email`, `password`, `terms` = true); sends the verification email |
| POST   | `/login`, `/logout`                | Session authentication; `/login` answers `429` + `retry_after` after 5 failures per email + IP |
| POST   | `/forgot-password`                 | Email a reset link (`email`); same answer whether or not the address exists |
| POST   | `/reset-password`                  | Set a new password (`token`, `email`, `password`, `password_confirmation`) |
| GET    | `/email/verify/{id}/{hash}`        | Signed link from the verification email (`expires`, `signature`); no session needed |
| POST   | `/email/verification-notification` | Resend the verification email to the signed-in user |
| GET    | `/user`                            | Current user with `email_verified`, organization, branch, subscription and latest join request |
| PUT    | `/profile`, `/password`            | Own name parts (`first_name`, `middle_name?`, `last_name`, `suffix?`), `phone`, `address?` and email (`current_password` required; a new email is un-verified and re-sent a link), own password |
| GET    | `/organizations/lookup?code=`      | A school's name and branches by code |
| POST   | `/organizations`                   | Admin without a school: register it (`name`, `contact_email?`, `contact_phone?`, `branches[]` of `{name, address?, is_main?}`, `plan_id?`) |
| POST   | `/join-requests`                   | Teacher or admin without a school: ask to join (`organization_code`, `branch_id`, `message?`, `role?` = `admin` to ask for the administrator role — admin accounts only) |
| DELETE | `/join-requests/{id}`              | Withdraw own pending request |
| GET    | `/school-years`, `/school-years/{id}` | The school's years (teacher: own counts and own current flag) |
| POST / PUT | `/school-years`, `/school-years/{id}` | Admin: register / edit (incl. the school default) |
| PUT    | `/school-years/{id}/select`        | Teacher: make it my current year |

### Teacher endpoints (the classes they teach)

Route-model binding is limited to what the teacher's teaching assignments
cover; anything else answers `404`.

| Method     | Path                            | Purpose |
|------------|----------------------------------|---------|
| GET        | `/dashboard/summary`             | Home analytics (`?school_year_id=` or `all`, `?grade_level_id=`, `?section_id=`, `?subject_id=`, `?pace_id=`) |
| GET        | `/curriculum`                    | The whole branch: grade levels with sections, subjects and who teaches what (`?school_year_id=`), to find open classes |
| GET        | `/grade-levels`, `/grade-levels/{id}` | The grade levels the teacher teaches in (their sections, every subject) / one with its records |
| GET        | `/sections`, `/sections/{id}`    | The teacher's sections (`?grade_level_id=`) / one with its subjects, teachers and the students they teach |
| GET        | `/subjects`, `/subjects/{id}`    | The subjects they teach (`?grade_level_id=`) / one with its records |
| GET        | `/teaching-assignments`          | What the teacher teaches (section + subject) |
| GET/POST   | `/teaching-assignment-requests`  | Own requests for open classes / ask (`section_id`, `subject_id`, `message?`; the subject must be offered by the section and have no teacher) |
| DELETE     | `/teaching-assignment-requests/{id}` | Withdraw a pending request |
| GET/POST   | `/students`                      | The students they teach (`?search=`, `?section_id=`, `?grade_level_id=`, `?school_year_id=`) / enroll (`section_id`, details, `subject_ids?` = subjects the section offers, at least one they teach; default every offered subject) |
| GET        | `/students/{id}`                 | One student with section, subjects taken, teachers and records |
| POST       | `/students/{id}/change-requests` | Propose changes to the details (any of `first_name`, `middle_name`, `last_name`, `extension`, `birth_date`, `sex`; `message?`); one pending per student |
| GET        | `/student-change-requests`       | Own proposals, pending first |
| DELETE     | `/student-change-requests/{id}`  | Withdraw a pending proposal |
| GET        | `/paces`, `/paces/{id}`          | The branch's PACE catalog, read-only: `pool_stock` and the teacher's own `my_stock` (`?search=`) |
| GET/POST   | `/pace-requests`                 | Own stock requests (`?status=`) / ask (`pace_id?` or `pace_number` + `pace_name`, `quantity`, `needed_by?`, `message?`) |
| POST       | `/pace-requests/batch`           | Several at once, all or nothing: `items[]` of `{pace_id?, pace_number?, pace_name?, quantity}` (max 50) + shared `needed_by?`, `message?` → one request per item |
| PUT/DELETE | `/pace-requests/{id}`            | Edit / withdraw a pending request |
| GET/POST   | `/students/{id}/assignments`     | List / assign PACEs to a student (`subject_id` must be taught by the teacher and taken by the student; an `issued_date` takes one of the teacher's booklets per PACE) |
| PUT/DELETE | `/assignments/{id}`              | Update or remove a PACE assignment, for subjects the teacher teaches (issuing / un-issuing moves stock) |

### Admin endpoints (`role:admin,owner`; the owner may add `?organization_id=`)

| Method | Path                              | Purpose |
|--------|------------------------------------|---------|
| GET    | `/admin/dashboard/summary`         | Analytics (`?branch_id=`, `?teacher_id=`, `?school_year_id=`, `?grade_level_id=`, `?section_id=`, `?subject_id=`, `?pace_id=`) |
| GET/PUT | `/admin/organization`             | The school's details, branches (with student counts), subscription, counts (incl. pending class and student-change requests) / update details (`admin_password`) |
| GET/POST | `/admin/branches`                | Branches / add one (plan limit) |
| PUT/DELETE | `/admin/branches/{id}`         | Rename, make main / remove (empty, non-main only) |
| GET    | `/admin/join-requests`             | Requests, pending first (`?status=`) |
| POST   | `/admin/join-requests/{id}/accept`, `.../decline` | Decide (`role?` = `admin`/`teacher` on accept, default the requested role, admins within the plan's limit; `reason?` on decline) |
| GET/POST | `/admin/plan-requests`           | The school's plan requests / ask (`action` = `change` + `plan_id`, or `cancel`; `message?`) |
| DELETE | `/admin/plan-requests/{id}`        | Withdraw the pending request |
| GET    | `/admin/users`                     | Members (`?search=`, `?role=`, `?branch_id=`) |
| GET/PUT | `/admin/users/{id}`               | One member / update name and email (`admin_password`) |
| PUT    | `/admin/users/{id}/password`       | Reset a member's password (`admin_password`), or change your own (`current_password`) |
| PUT    | `/admin/users/{id}/branch`         | Move a member to another branch |
| PUT    | `/admin/users/{id}/role`           | Make a member an `admin` or a `teacher` (plan's admin limit; not your own role; never the last admin) |
| DELETE | `/admin/users/{id}/membership`     | Remove a teacher from the school |
| GET/POST | `/admin/grade-levels`            | Grade levels with sections and subjects (`?branch_id=`, `?teacher_id=`, `?school_year_id=`) / add one (`branch_id`, `school_year_id`, `name`, `sort_order?`) |
| GET/PUT/DELETE | `/admin/grade-levels/{id}` | One grade level with every record / rename, reorder / delete (refused while students are enrolled) |
| POST   | `/admin/grade-levels/{id}/subjects`, `/admin/grade-levels/{id}/sections` | Add a subject (offered by every section) / a section (offers every subject) |
| GET/PUT/DELETE | `/admin/subjects/{id}`, `/admin/sections/{id}` | One subject or section / rename, reorder / delete (a section only without students) |
| POST   | `/admin/sections/{id}/promote`     | Move the roster in one go: `moves[]` of `{student_id, section_id\|null}` (null = stays) → `{moved, stayed, students}` |
| PUT/DELETE | `/admin/sections/{id}/subjects/{subject}/offer` | The section starts / stops offering a subject (stopping is refused while a teacher is assigned or students take it) |
| GET    | `/admin/teaching-assignments`      | Who teaches what (`?branch_id=`, `?teacher_id=`, `?section_id=`) |
| PUT/DELETE | `/admin/sections/{id}/subjects/{subject}/teacher` | Assign a teacher (`teacher_id`; replaces the current one, must be in the section's branch, subject must be offered) / take the teacher off |
| GET    | `/admin/teaching-assignment-requests` | Teachers' requests for open classes, pending first (`?status=`, `?branch_id=`) |
| POST   | `/admin/teaching-assignment-requests/{id}/accept`, `.../decline` | Decide (`reason?` on decline); accepting assigns the teacher |
| GET/POST | `/admin/students`                | Every student of the school (`?search=`, `?teacher_id=`, `?branch_id=`, `?grade_level_id=`, `?section_id=`, `?school_year_id=`) / enroll (`section_id`, details, `subject_ids?`) |
| GET/PUT/DELETE | `/admin/students/{id}`     | One student (with the organization's school years) / edit, move to another section (`section_id`, `subject_ids?`; dropping a subject with records is refused) / delete |
| GET    | `/admin/student-change-requests`   | Teachers' proposed corrections, pending first (`?status=`) |
| POST   | `/admin/student-change-requests/{id}/accept`, `.../decline` | Decide (accepting updates the student; `reason?` on decline) |
| GET    | `/admin/school-years`              | The school's years (`?teacher_id=` picks that teacher's school) |
| GET/POST | `/admin/paces`                   | Catalog (`?search=`, `?branch_id=`, `?teacher_id=` = PACEs that teacher holds) with `pool_stock`, holdings / add one (`branch_id`, `name`, `number`, `pool_stock?`) |
| POST   | `/admin/paces/range`               | Register a number range (`branch_id`, `name`, `start`, `end`, `pool_stock?`) |
| GET/PUT/DELETE | `/admin/paces/{id}`        | One PACE with holdings and records / rename, renumber / delete |
| POST   | `/admin/paces/{id}/stock/receive`  | Booklets arrived: add to the pool (`quantity`, `note?`) |
| PATCH  | `/admin/paces/{id}/stock/pool`     | Correct the pool by a signed number (`delta`, `note?`; never below zero) |
| POST   | `/admin/paces/stock/batch`         | One pool change for many PACEs, all or nothing (`pace_ids[]`, `mode` = `receive` / `adjust` / `set`, `quantity`, `note?`) |
| POST   | `/admin/paces/{id}/stock/distribute` | Give booklets from the pool to a teacher (`teacher_id`, `quantity`, `note?`) |
| PUT    | `/admin/paces/{id}/stock/holdings/{teacher}` | Set what a teacher holds (`quantity`; the difference moves to or from the pool) |
| GET    | `/admin/paces/{id}/movements`      | The stock ledger, newest first |
| GET    | `/admin/pace-requests`             | Teachers' stock requests, pending first (`?status=`, `?teacher_id=`, `?branch_id=`, `?search=`, `?needed_from=` / `?needed_to=`) |
| PUT    | `/admin/pace-requests/{id}`        | Edit a pending request (`pace_number`, `pace_name`, `quantity`, `needed_by`) |
| POST   | `/admin/pace-requests/{id}/give`, `.../decline` | Decide (`quantity?`, `decision_note?`); giving moves the quantity from the pool to the teacher (`422` on `quantity` when the pool is short; quantity 0 only adds a new PACE to the catalog) |
| POST   | `/admin/pace-requests/batch`       | Decide several (`action` = `give` \| `decline`, `ids[]` up to 100, `decision_note?` for all): oldest first, each on its own; returns `{done[], skipped[{id, teacher_name, pace_number, pace_name, quantity, reason}]}`. Skipped: pool too small, PACE not in the catalog yet, already decided. An id outside the school makes the whole batch `404` |

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
| PUT    | `/owner/users/{id}/role`           | Switch a member between `admin` and `teacher` (same rules as the admin route) |
| GET    | `/owner/unassigned-users`          | Accounts in no school, longest waiting first (`?search=`, `?role=teacher|admin`, `?from=` / `?to=` = day created, `YYYY-MM-DD`) |
| DELETE | `/owner/unassigned-users/{id}`     | Delete one (`admin_password`); refused while it owns records |
| GET/POST | `/owner/staff`                   | Owner only: platform accounts / add one (`role` = `owner` or `vice_admin`, the name in parts `first_name`, `middle_name?`, `last_name`, `suffix?`, `phone`, `address?`, `email`, `password`) |
| PUT    | `/owner/staff/{id}/role`           | Owner only: switch between owner and vice admin (never the last owner) |
| DELETE | `/owner/staff/{id}`                | Owner only: remove a staff account (not your own, never the last owner) |
