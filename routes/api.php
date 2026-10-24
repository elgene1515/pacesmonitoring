<?php

use App\Http\Controllers\Api\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\GradeLevelController as AdminGradeLevelController;
use App\Http\Controllers\Api\Admin\JoinRequestController as AdminJoinRequestController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\PaceController as AdminPaceController;
use App\Http\Controllers\Api\Admin\PaceRequestController as AdminPaceRequestController;
use App\Http\Controllers\Api\Admin\PlanRequestController as AdminPlanRequestController;
use App\Http\Controllers\Api\Admin\SchoolYearController as AdminSchoolYearController;
use App\Http\Controllers\Api\Admin\SectionController as AdminSectionController;
use App\Http\Controllers\Api\Admin\StudentChangeRequestController as AdminStudentChangeRequestController;
use App\Http\Controllers\Api\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Api\Admin\SubjectController as AdminSubjectController;
use App\Http\Controllers\Api\Admin\TeachingAssignmentRequestController as AdminTeachingAssignmentRequestController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GradeLevelController;
use App\Http\Controllers\Api\JoinRequestController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\Owner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\Api\Owner\OrganizationController as OwnerOrganizationController;
use App\Http\Controllers\Api\Owner\PlanController as OwnerPlanController;
use App\Http\Controllers\Api\Owner\PlanRequestController as OwnerPlanRequestController;
use App\Http\Controllers\Api\Owner\StaffController as OwnerStaffController;
use App\Http\Controllers\Api\Owner\SubscriptionController as OwnerSubscriptionController;
use App\Http\Controllers\Api\Owner\UnassignedUserController as OwnerUnassignedUserController;
use App\Http\Controllers\Api\Owner\UserController as OwnerUserController;
use App\Http\Controllers\Api\PaceAssignmentController;
use App\Http\Controllers\Api\PaceController;
use App\Http\Controllers\Api\PaceRequestController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SchoolYearController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\StudentAssignmentController;
use App\Http\Controllers\Api\StudentChangeRequestController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeachingAssignmentController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api and return JSON. Authentication uses
| Sanctum's SPA cookie/session flow: the SPA first hits
| /sanctum/csrf-cookie, then POSTs to /api/login or /api/register.
|
| Roles:
|  - teacher     works on what their teaching assignments cover: the
|                sections they teach in, those sections' students, the
|                subjects (and PACE records) they are in charge of, and
|                their branch's PACE catalog. Route-model binding is limited
|                to that, so anything else answers 404. Teachers enroll
|                students and propose changes to their details; they ask the
|                admin for teaching slots and for PACE booklets.
|  - admin       sets up one organization: branches, school years, the
|                curriculum (grade levels, subjects, sections), who teaches
|                what, the PACE catalogs and the stock ledger; enrolls and
|                edits students; decides the teachers' requests; and has a
|                read-only analytics area across every teacher.
|  - owner       the platform owner: every organization, plans, subscriptions,
|                plan requests and the platform staff.
|  - vice_admin  platform staff appointed by the owner: same as the owner
|                except managing staff, deactivating organizations and
|                deleting plans.
|  Platform staff may use the admin endpoints too, optionally narrowed with
|  ?organization_id=.
|
| `tenant` requires an organization membership and, for anything but GET, an
| active subscription (a lapsed organization is read-only).
|
*/

Route::post('/register', [AuthController::class, 'register'])->name('api.register');
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

// Self-service password reset: a link is emailed, then the token sets a new password.
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->name('api.password.forgot');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('api.password.reset');
});

// Emailed verification link (signed, so no session is required). The route name is what the signature is computed for.
Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
    ->middleware(['signed:relative', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/user', [AuthController::class, 'user'])->name('api.user');

    Route::put('/profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('api.password.update');
    Route::post('/email/verification-notification', [VerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // The plans a school can pick (sign-up, plan change requests).
    Route::get('/plans', [PlanController::class, 'index'])->name('api.plans.index');

    /*
    | Onboarding (no organization yet): an admin registers their school, a
    | teacher looks a school up by code and asks to join one of its branches.
    */
    Route::get('/organizations/lookup', [OrganizationController::class, 'lookup'])->name('api.organizations.lookup');
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->middleware('role:admin')
        ->name('api.organizations.store');
    // Teachers join as teachers; an admin-type account may join a registered school as an administrator.
    Route::post('/join-requests', [JoinRequestController::class, 'store'])
        ->middleware('role:teacher,admin')
        ->name('api.join-requests.store');
    Route::delete('/join-requests/{joinRequest}', [JoinRequestController::class, 'destroy'])
        ->middleware('role:teacher,admin')
        ->name('api.join-requests.destroy');

    /*
    | School years: one list per organization. Everyone reads it, admins
    | manage it, teachers pick their own current year.
    */
    Route::middleware(['role:teacher,admin,owner,vice_admin', 'tenant'])->group(function (): void {
        Route::get('/school-years', [SchoolYearController::class, 'index'])->name('api.school-years.index');
        Route::get('/school-years/{schoolYear}', [SchoolYearController::class, 'show'])->name('api.school-years.show');
    });

    Route::middleware(['role:admin,owner,vice_admin', 'tenant'])->group(function (): void {
        Route::post('/school-years', [SchoolYearController::class, 'store'])->name('api.school-years.store');
        Route::match(['put', 'patch'], '/school-years/{schoolYear}', [SchoolYearController::class, 'update'])->name('api.school-years.update');
    });

    Route::put('/school-years/{schoolYear}/select', [SchoolYearController::class, 'select'])
        ->middleware(['role:teacher', 'tenant'])
        ->name('api.school-years.select');

    /*
    | Teacher area: what the teacher's teaching assignments cover.
    */
    Route::middleware(['role:teacher', 'tenant'])->group(function (): void {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');

        // The curriculum, read-only: the grade levels, sections and subjects the teacher teaches.
        Route::get('/curriculum', [GradeLevelController::class, 'branch'])->name('api.curriculum.index');
        Route::get('/grade-levels', [GradeLevelController::class, 'index'])->name('api.grade-levels.index');
        Route::get('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'show'])->name('api.grade-levels.show');
        Route::get('/subjects', [SubjectController::class, 'index'])->name('api.subjects.index');
        Route::get('/subjects/{subject}', [SubjectController::class, 'show'])->name('api.subjects.show');
        Route::get('/sections', [SectionController::class, 'index'])->name('api.sections.index');
        Route::get('/sections/{section}', [SectionController::class, 'show'])->name('api.sections.show');

        // Teaching assignments and requests for open slots.
        Route::get('/teaching-assignments', [TeachingAssignmentController::class, 'index'])->name('api.teaching-assignments.index');
        Route::get('/teaching-assignment-requests', [TeachingAssignmentController::class, 'requests'])->name('api.teaching-assignment-requests.index');
        Route::post('/teaching-assignment-requests', [TeachingAssignmentController::class, 'storeRequest'])->name('api.teaching-assignment-requests.store');
        Route::delete('/teaching-assignment-requests/{teachingAssignmentRequest}', [TeachingAssignmentController::class, 'destroyRequest'])->name('api.teaching-assignment-requests.destroy');

        // Students of the teacher's sections: enroll, view, propose changes.
        Route::get('/students', [StudentController::class, 'index'])->name('api.students.index');
        Route::post('/students', [StudentController::class, 'store'])->name('api.students.store');
        Route::get('/students/{student}', [StudentController::class, 'show'])->name('api.students.show');
        Route::post('/students/{student}/change-requests', [StudentController::class, 'proposeChange'])->name('api.students.change-requests.store');
        Route::get('/student-change-requests', [StudentChangeRequestController::class, 'index'])->name('api.student-change-requests.index');
        Route::delete('/student-change-requests/{studentChangeRequest}', [StudentChangeRequestController::class, 'destroy'])->name('api.student-change-requests.destroy');

        // The branch's PACE catalog, read-only, with the teacher's own booklets.
        Route::get('/paces', [PaceController::class, 'index'])->name('api.paces.index');
        Route::get('/paces/{pace}', [PaceController::class, 'show'])->name('api.paces.show');

        // Stock requests to the school admin (edit / withdraw while pending).
        Route::get('/pace-requests', [PaceRequestController::class, 'index'])->name('api.pace-requests.index');
        Route::post('/pace-requests', [PaceRequestController::class, 'store'])->name('api.pace-requests.store');
        Route::post('/pace-requests/batch', [PaceRequestController::class, 'storeBatch'])->name('api.pace-requests.store-batch');
        Route::match(['put', 'patch'], '/pace-requests/{paceRequest}', [PaceRequestController::class, 'update'])->name('api.pace-requests.update');
        Route::delete('/pace-requests/{paceRequest}', [PaceRequestController::class, 'destroy'])->name('api.pace-requests.destroy');

        // PACE records: every record of a student is visible; assigning and editing is limited to the teacher's subjects.
        Route::get('/students/{student}/assignments', [StudentAssignmentController::class, 'index'])
            ->name('api.students.assignments.index');
        Route::post('/students/{student}/assignments', [StudentAssignmentController::class, 'store'])
            ->name('api.students.assignments.store');

        Route::match(['put', 'patch'], '/assignments/{assignment}', [PaceAssignmentController::class, 'update'])
            ->name('api.assignments.update');
        Route::delete('/assignments/{assignment}', [PaceAssignmentController::class, 'destroy'])
            ->name('api.assignments.destroy');
    });

    /*
    | Admin area: one organization (the admin's own; platform staff may pass
    | ?organization_id= or see every organization at once).
    */
    Route::middleware(['role:admin,owner,vice_admin', 'tenant'])->prefix('admin')->name('api.admin.')->group(function (): void {
        Route::get('/dashboard/summary', [AdminDashboardController::class, 'summary'])->name('dashboard.summary');

        Route::get('/organization', [AdminOrganizationController::class, 'show'])->name('organization.show');
        Route::put('/organization', [AdminOrganizationController::class, 'update'])->name('organization.update');

        Route::get('/branches', [AdminBranchController::class, 'index'])->name('branches.index');
        Route::post('/branches', [AdminBranchController::class, 'store'])->name('branches.store');
        Route::put('/branches/{branch}', [AdminBranchController::class, 'update'])->name('branches.update');
        Route::delete('/branches/{branch}', [AdminBranchController::class, 'destroy'])->name('branches.destroy');

        Route::get('/join-requests', [AdminJoinRequestController::class, 'index'])->name('join-requests.index');
        Route::post('/join-requests/{joinRequest}/accept', [AdminJoinRequestController::class, 'accept'])->name('join-requests.accept');
        Route::post('/join-requests/{joinRequest}/decline', [AdminJoinRequestController::class, 'decline'])->name('join-requests.decline');

        Route::get('/plan-requests', [AdminPlanRequestController::class, 'index'])->name('plan-requests.index');
        Route::post('/plan-requests', [AdminPlanRequestController::class, 'store'])->name('plan-requests.store');
        Route::delete('/plan-requests/{planRequest}', [AdminPlanRequestController::class, 'destroy'])->name('plan-requests.destroy');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/password', [AdminUserController::class, 'updatePassword'])->name('users.password');
        Route::put('/users/{user}/branch', [AdminUserController::class, 'updateBranch'])->name('users.branch');
        Route::put('/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
        Route::delete('/users/{user}/membership', [AdminUserController::class, 'destroyMembership'])->name('users.membership.destroy');

        // The curriculum: grade levels (per branch and school year), their subjects and sections.
        Route::get('/grade-levels', [AdminGradeLevelController::class, 'index'])->name('grade-levels.index');
        Route::post('/grade-levels', [AdminGradeLevelController::class, 'store'])->name('grade-levels.store');
        Route::get('/grade-levels/{gradeLevel}', [AdminGradeLevelController::class, 'show'])->name('grade-levels.show');
        Route::match(['put', 'patch'], '/grade-levels/{gradeLevel}', [AdminGradeLevelController::class, 'update'])->name('grade-levels.update');
        Route::delete('/grade-levels/{gradeLevel}', [AdminGradeLevelController::class, 'destroy'])->name('grade-levels.destroy');

        Route::post('/grade-levels/{gradeLevel}/subjects', [AdminSubjectController::class, 'store'])->name('subjects.store');
        Route::get('/subjects/{subject}', [AdminSubjectController::class, 'show'])->name('subjects.show');
        Route::match(['put', 'patch'], '/subjects/{subject}', [AdminSubjectController::class, 'update'])->name('subjects.update');
        Route::delete('/subjects/{subject}', [AdminSubjectController::class, 'destroy'])->name('subjects.destroy');

        Route::post('/grade-levels/{gradeLevel}/sections', [AdminSectionController::class, 'store'])->name('sections.store');
        Route::get('/sections/{section}', [AdminSectionController::class, 'show'])->name('sections.show');
        Route::match(['put', 'patch'], '/sections/{section}', [AdminSectionController::class, 'update'])->name('sections.update');
        Route::delete('/sections/{section}', [AdminSectionController::class, 'destroy'])->name('sections.destroy');
        Route::post('/sections/{section}/promote', [AdminSectionController::class, 'promote'])->name('sections.promote');

        // Who teaches what: assign directly, or decide the teachers' requests.
        Route::put('/sections/{section}/subjects/{subject}/offer', [AdminSectionController::class, 'offer'])->name('sections.subjects.offer');
        Route::delete('/sections/{section}/subjects/{subject}/offer', [AdminSectionController::class, 'withdraw'])->name('sections.subjects.withdraw');
        Route::get('/teaching-assignments', [AdminSectionController::class, 'assignments'])->name('teaching-assignments.index');
        Route::put('/sections/{section}/subjects/{subject}/teacher', [AdminSectionController::class, 'assign'])->name('teaching-assignments.assign');
        Route::delete('/sections/{section}/subjects/{subject}/teacher', [AdminSectionController::class, 'unassign'])->name('teaching-assignments.unassign');
        Route::get('/teaching-assignment-requests', [AdminTeachingAssignmentRequestController::class, 'index'])->name('teaching-assignment-requests.index');
        Route::post('/teaching-assignment-requests/{teachingAssignmentRequest}/accept', [AdminTeachingAssignmentRequestController::class, 'accept'])->name('teaching-assignment-requests.accept');
        Route::post('/teaching-assignment-requests/{teachingAssignmentRequest}/decline', [AdminTeachingAssignmentRequestController::class, 'decline'])->name('teaching-assignment-requests.decline');

        // Students: every student of the organization; enroll, edit, move, remove; decide teachers' change proposals.
        Route::get('/students', [AdminStudentController::class, 'index'])->name('students.index');
        Route::post('/students', [AdminStudentController::class, 'store'])->name('students.store');
        Route::get('/students/{student}', [AdminStudentController::class, 'show'])->name('students.show');
        Route::match(['put', 'patch'], '/students/{student}', [AdminStudentController::class, 'update'])->name('students.update');
        Route::delete('/students/{student}', [AdminStudentController::class, 'destroy'])->name('students.destroy');
        Route::get('/student-change-requests', [AdminStudentChangeRequestController::class, 'index'])->name('student-change-requests.index');
        Route::post('/student-change-requests/{studentChangeRequest}/accept', [AdminStudentChangeRequestController::class, 'accept'])->name('student-change-requests.accept');
        Route::post('/student-change-requests/{studentChangeRequest}/decline', [AdminStudentChangeRequestController::class, 'decline'])->name('student-change-requests.decline');

        Route::get('/school-years', [AdminSchoolYearController::class, 'index'])->name('school-years.index');

        // The PACE catalogs and the stock ledger.
        Route::get('/paces', [AdminPaceController::class, 'index'])->name('paces.index');
        Route::post('/paces', [AdminPaceController::class, 'store'])->name('paces.store');
        Route::post('/paces/range', [AdminPaceController::class, 'storeRange'])->name('paces.range');
        Route::post('/paces/stock/batch', [AdminPaceController::class, 'batchStock'])->name('paces.stock.batch');
        Route::get('/paces/{pace}', [AdminPaceController::class, 'show'])->name('paces.show');
        Route::match(['put', 'patch'], '/paces/{pace}', [AdminPaceController::class, 'update'])->name('paces.update');
        Route::delete('/paces/{pace}', [AdminPaceController::class, 'destroy'])->name('paces.destroy');
        Route::post('/paces/{pace}/stock/receive', [AdminPaceController::class, 'receive'])->name('paces.stock.receive');
        Route::patch('/paces/{pace}/stock/pool', [AdminPaceController::class, 'adjustPool'])->name('paces.stock.pool');
        Route::post('/paces/{pace}/stock/distribute', [AdminPaceController::class, 'distribute'])->name('paces.stock.distribute');
        Route::put('/paces/{pace}/stock/holdings/{teacher}', [AdminPaceController::class, 'setHolding'])->name('paces.stock.holding');
        Route::get('/paces/{pace}/movements', [AdminPaceController::class, 'movements'])->name('paces.movements');

        // The teachers' stock requests: adjust, give (from the pool) or decline.
        Route::get('/pace-requests', [AdminPaceRequestController::class, 'index'])->name('pace-requests.index');
        Route::match(['put', 'patch'], '/pace-requests/{paceRequest}', [AdminPaceRequestController::class, 'update'])->name('pace-requests.update');
        Route::post('/pace-requests/batch', [AdminPaceRequestController::class, 'batch'])->name('pace-requests.batch');
        Route::post('/pace-requests/{paceRequest}/give', [AdminPaceRequestController::class, 'give'])->name('pace-requests.give');
        Route::post('/pace-requests/{paceRequest}/decline', [AdminPaceRequestController::class, 'decline'])->name('pace-requests.decline');
    });

    /*
    | Platform area (owner and vice admins): every organization, plans,
    | subscriptions and plan requests. Staff management, deactivating
    | organizations and deleting plans stay with the owner.
    */
    Route::middleware('role:owner,vice_admin')->prefix('owner')->name('api.owner.')->group(function (): void {
        Route::get('/dashboard/summary', [OwnerDashboardController::class, 'summary'])->name('dashboard.summary');

        Route::get('/organizations', [OwnerOrganizationController::class, 'index'])->name('organizations.index');
        Route::get('/organizations/{organization}', [OwnerOrganizationController::class, 'show'])->withTrashed()->name('organizations.show');
        Route::put('/organizations/{organization}', [OwnerOrganizationController::class, 'update'])->withTrashed()->name('organizations.update');
        Route::delete('/organizations/{organization}', [OwnerOrganizationController::class, 'destroy'])
            ->middleware('role:owner')
            ->name('organizations.destroy');
        Route::post('/organizations/{organization}/restore', [OwnerOrganizationController::class, 'restore'])
            ->withTrashed()
            ->middleware('role:owner')
            ->name('organizations.restore');

        Route::get('/organizations/{organization}/subscriptions', [OwnerSubscriptionController::class, 'index'])->withTrashed()->name('subscriptions.index');
        Route::post('/organizations/{organization}/subscriptions', [OwnerSubscriptionController::class, 'store'])->withTrashed()->name('subscriptions.store');
        Route::put('/subscriptions/{subscription}', [OwnerSubscriptionController::class, 'update'])->name('subscriptions.update');

        Route::get('/plan-requests', [OwnerPlanRequestController::class, 'index'])->name('plan-requests.index');
        Route::post('/plan-requests/{planRequest}/approve', [OwnerPlanRequestController::class, 'approve'])->name('plan-requests.approve');
        Route::post('/plan-requests/{planRequest}/decline', [OwnerPlanRequestController::class, 'decline'])->name('plan-requests.decline');

        Route::get('/plans', [OwnerPlanController::class, 'index'])->name('plans.index');
        Route::post('/plans', [OwnerPlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{plan}', [OwnerPlanController::class, 'update'])->name('plans.update');
        Route::delete('/plans/{plan}', [OwnerPlanController::class, 'destroy'])->middleware('role:owner')->name('plans.destroy');

        Route::put('/users/{user}/role', [OwnerUserController::class, 'updateRole'])->name('users.role');

        // Accounts that belong to no organization yet: review them, delete the strays.
        Route::get('/unassigned-users', [OwnerUnassignedUserController::class, 'index'])->name('unassigned-users.index');
        Route::delete('/unassigned-users/{user}', [OwnerUnassignedUserController::class, 'destroy'])->name('unassigned-users.destroy');

        Route::middleware('role:owner')->group(function (): void {
            Route::get('/staff', [OwnerStaffController::class, 'index'])->name('staff.index');
            Route::post('/staff', [OwnerStaffController::class, 'store'])->name('staff.store');
            Route::put('/staff/{user}/role', [OwnerStaffController::class, 'updateRole'])->name('staff.role');
            Route::delete('/staff/{user}', [OwnerStaffController::class, 'destroy'])->name('staff.destroy');
        });
    });
});

/*
| Unknown GET paths under /api must answer with a JSON 404 instead of falling
| through to the SPA catch-all in routes/web.php. API routes are registered
| before web routes, and the routes above are matched first, so this only
| catches paths nothing else claimed.
*/
Route::get('/{any}', fn () => abort(404, 'Not Found.'))->where('any', '.*')->name('api.fallback');
