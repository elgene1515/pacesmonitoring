<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\JoinRequest;
use App\Models\PaceRequest;
use App\Models\PlanRequest;
use App\Models\StudentChangeRequest;
use App\Models\TeachingAssignmentRequest;
use App\Models\User;
use App\Support\PersonDetails;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new account (as a school administrator or a teacher) and
     * start a session for it. The next step depends on the role: an admin
     * registers their organization, a teacher asks to join one.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $person = $request->person();

        $user = new User($person + [
            'name' => PersonDetails::fullName($person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix']),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);
        $user->role = Role::from($request->validated('role'));

        // Who accepted what, and when: the Terms of Service and Privacy Policy version in force.
        $user->forceFill([
            'terms_accepted_at' => now(),
            'terms_version' => config('saas.terms_version'),
        ]);
        $user->save();

        // Sends the verification email (SendEmailVerificationNotification listener).
        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource(static::withMembership($user)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Authenticate with email and password (session / cookie based).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        return $this->userResponse(Auth::guard('web')->user());
    }

    /**
     * End the current session.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * The currently authenticated user, with their organization, branch,
     * subscription state and latest join request, so the SPA can route them.
     */
    public function user(Request $request): JsonResponse
    {
        return $this->userResponse($request->user());
    }

    /**
     * Load everything the SPA needs to know about the signed-in user's membership.
     */
    public static function withMembership(User $user): User
    {
        $user->load([
            'organization.currentSubscription.plan',
            'branch',
            'latestJoinRequest.organization',
            'latestJoinRequest.branch',
        ]);

        if ($user->organization !== null) {
            $user->organization->loadCount([
                'branches',
                'teachers',
                'admins',
                'pendingJoinRequests',
                'pendingPlanRequests',
                'pendingPaceRequests',
                'pendingTeachingAssignmentRequests',
                'pendingStudentChangeRequests',
            ]);
        }

        if ($user->isPlatform()) {
            $user->setAttribute('pending_counts', static::platformPendingCounts());
        }

        return $user;
    }

    /**
     * What is waiting for an answer across every (active) organization: the
     * badges on the platform staff's navigation tabs.
     *
     * @return array{join_requests: int, plan_requests: int, pace_requests: int, teaching_requests: int, student_changes: int}
     */
    public static function platformPendingCounts(): array
    {
        $count = static fn (string $model): int => $model::query()->pending()->whereHas('organization')->count();

        return [
            'join_requests' => $count(JoinRequest::class),
            'plan_requests' => $count(PlanRequest::class),
            'pace_requests' => $count(PaceRequest::class),
            'teaching_requests' => $count(TeachingAssignmentRequest::class),
            'student_changes' => $count(StudentChangeRequest::class),
        ];
    }

    /**
     * A 200 user payload. The status is pinned explicitly because a resource
     * wrapping a model flagged wasRecentlyCreated would otherwise answer 201.
     */
    protected function userResponse(User $user): JsonResponse
    {
        return (new UserResource(static::withMembership($user)))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
