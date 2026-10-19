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
use App\Http\Controllers\Api\Admin\StudentController as AdminStudentController;
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
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SchoolYearController;
use App\Http\Controllers\Api\StudentAssignmentController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubjectController;
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
|  - teacher     own records only (students, PACEs, grade levels, records);
|                route-model binding is owner-scoped, so a record that belongs
|                to someone else answers 404.
|  - admin       manages one organization: branches, teachers (join requests),
|                school years, plan requests, and read-only analytics across
|                its teachers.
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

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/user', [AuthController::class, 'user'])->name('api.user');

    Route::put('/profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('api.password.update');

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
    Route::post('/join-requests', [JoinRequestController::class, 'store'])
        ->middleware('role:teacher')
        ->name('api.join-requests.store');
    Route::delete('/join-requests/{joinRequest}', [JoinRequestController::class, 'destroy'])
        ->middleware('role:teacher')
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
    | Teacher area: the signed-in teacher's own records.
    */
    Route::middleware(['role:teacher', 'tenant'])->group(function (): void {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');

        Route::apiResource('grade-levels', GradeLevelController::class)
            ->parameters(['grade-levels' => 'gradeLevel'])
            ->names('api.grade-levels');

        Route::apiResource('subjects', SubjectController::class)
            ->names('api.subjects');

        Route::apiResource('students', StudentController::class)->names('api.students');
        Route::post('/paces/range', [PaceController::class, 'storeRange'])->name('api.paces.range');
        Route::patch('/paces/{pace}/stock', [PaceController::class, 'adjustStock'])->name('api.paces.stock');
        Route::apiResource('paces', PaceController::class)->names('api.paces');

        // Stock requests to the school admin (edit / withdraw while pending).
        Route::get('/pace-requests', [PaceRequestController::class, 'index'])->name('api.pace-requests.index');
        Route::post('/pace-requests', [PaceRequestController::class, 'store'])->name('api.pace-requests.store');
        Route::match(['put', 'patch'], '/pace-requests/{paceRequest}', [PaceRequestController::class, 'update'])->name('api.pace-requests.update');
        Route::delete('/pace-requests/{paceRequest}', [PaceRequestController::class, 'destroy'])->name('api.pace-requests.destroy');

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
        Route::delete('/users/{user}/membership', [AdminUserController::class, 'destroyMembership'])->name('users.membership.destroy');

        Route::get('/students', [AdminStudentController::class, 'index'])->name('students.index');
        Route::get('/students/{student}', [AdminStudentController::class, 'show'])->name('students.show');

        Route::get('/grade-levels', [AdminGradeLevelController::class, 'index'])->name('grade-levels.index');
        Route::get('/grade-levels/{gradeLevel}', [AdminGradeLevelController::class, 'show'])->name('grade-levels.show');
        Route::get('/subjects/{subject}', [AdminGradeLevelController::class, 'showSubject'])->name('subjects.show');
        Route::get('/school-years', [AdminSchoolYearController::class, 'index'])->name('school-years.index');

        Route::get('/paces', [AdminPaceController::class, 'index'])->name('paces.index');
        Route::get('/paces/{pace}', [AdminPaceController::class, 'show'])->name('paces.show');

        // The teachers' PACE stock requests: adjust, hand over (tops up the PACE's stock) or decline.
        Route::get('/pace-requests', [AdminPaceRequestController::class, 'index'])->name('pace-requests.index');
        Route::match(['put', 'patch'], '/pace-requests/{paceRequest}', [AdminPaceRequestController::class, 'update'])->name('pace-requests.update');
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
