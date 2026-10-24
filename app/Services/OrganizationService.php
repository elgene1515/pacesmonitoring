<?php

namespace App\Services;

use App\Enums\JoinRequestStatus;
use App\Models\Branch;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organizations (schools) and their branches.
 */
class OrganizationService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanRequestService $planRequests,
    ) {
    }

    /**
     * Register the organization of a freshly signed-up admin: the organization
     * itself, its branches (the first one is the main branch; "Main" when none
     * are given), a trial subscription, and the admin's membership. A paid
     * plan chosen at sign-up becomes a pending plan request for the owner.
     *
     * @param  array<string, mixed>  $data  name, address?, contact_email?, contact_phone?, branches?: string[], plan_id?
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function register(User $admin, array $data): Organization
    {
        if ($admin->hasOrganization()) {
            throw ValidationException::withMessages([
                'organization' => ['You already belong to an organization.'],
            ]);
        }

        $branches = $this->normalizeBranches($data['branches'] ?? []);

        // The branches have to fit the plan the school signs up for; without a paid pick it is the trial plan.
        $plan = ! empty($data['plan_id'])
            ? Plan::query()->find((int) $data['plan_id'])
            : null;
        $plan ??= $this->subscriptions->defaultPlan();

        if ($plan->max_branches !== null && count($branches) > $plan->max_branches) {
            throw ValidationException::withMessages([
                'branches' => [sprintf(
                    'The %s plan allows up to %d %s, but you named %d. Remove some, or pick a plan with more branches.',
                    $plan->name,
                    $plan->max_branches,
                    $plan->max_branches === 1 ? 'branch' : 'branches',
                    count($branches)
                )],
            ]);
        }

        return DB::transaction(function () use ($admin, $data, $branches): Organization {
            $organization = Organization::create([
                'name' => trim((string) $data['name']),
                'code' => Organization::generateCode(),
                // The school's address is read from its main branch (see OrganizationResource).
                'address' => $data['address'] ?? null,
                'contact_email' => $data['contact_email'] ?? $admin->email,
                'contact_phone' => $data['contact_phone'] ?? null,
                'created_by' => $admin->id,
            ]);

            $main = null;

            foreach ($branches as $input) {
                $branch = $this->createBranch($organization, $input, false);

                if ($input['is_main']) {
                    $main = $branch;
                }
            }

            $this->subscriptions->startTrial($organization, $admin);

            $admin->organization_id = $organization->id;
            $admin->branch_id = $main?->id;
            $admin->save();

            // Registering a school settles it: a request they sent to join another one is withdrawn.
            JoinRequest::query()
                ->where('user_id', $admin->id)
                ->pending()
                ->update(['status' => JoinRequestStatus::Cancelled->value, 'decided_at' => now()]);

            if (! empty($data['plan_id'])) {
                $plan = Plan::query()->find((int) $data['plan_id']);

                if ($plan !== null) {
                    $this->planRequests->submit($organization, $admin, PlanRequest::ACTION_CHANGE, $plan, 'Chosen at sign-up.');
                }
            }

            return $this->detail($organization);
        });
    }

    /**
     * Branch inputs as [{name, address, is_main}]: strings are accepted as bare
     * names, blanks and duplicates are dropped, exactly one branch is main (the
     * flagged one, else the first), and an empty list yields a "Main" branch.
     *
     * @param  array<int, mixed>  $inputs
     * @return array<int, array{name: string, address: ?string, is_main: bool}>
     */
    protected function normalizeBranches(array $inputs): array
    {
        $seen = [];
        $branches = [];

        foreach ($inputs as $input) {
            $name = trim((string) (is_array($input) ? ($input['name'] ?? '') : $input));
            $key = mb_strtolower($name);

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $address = is_array($input) ? trim((string) ($input['address'] ?? '')) : '';

            $branches[] = [
                'name' => $name,
                'address' => $address === '' ? null : $address,
                'is_main' => is_array($input) && filter_var($input['is_main'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        if ($branches === []) {
            $branches[] = ['name' => 'Main', 'address' => null, 'is_main' => true];
        }

        $mainIndex = array_search(true, array_column($branches, 'is_main'), true);
        $mainIndex = $mainIndex === false ? 0 : $mainIndex;

        foreach ($branches as $index => $branch) {
            $branches[$index]['is_main'] = $index === $mainIndex;
        }

        return $branches;
    }

    /**
     * Deactivate an organization (soft delete): its members lose access and
     * its code can no longer be joined, but nothing is removed and it can be
     * restored. Pending join and plan requests are withdrawn.
     */
    public function deactivate(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            $organization->pendingJoinRequests()->update(['status' => 'cancelled', 'decided_at' => now()]);
            $organization->pendingPlanRequests()->update(['status' => 'cancelled', 'decided_at' => now()]);
            $organization->pendingPaceRequests()->update(['status' => 'cancelled', 'decided_at' => now()]);
            $organization->delete();
        });
    }

    /**
     * Bring a deactivated organization back.
     */
    public function restore(Organization $organization): Organization
    {
        $organization->restore();

        return $this->detail($organization);
    }

    /**
     * Update the organization's details.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $organization, array $data): Organization
    {
        $organization->fill($data);
        $organization->save();

        return $this->detail($organization);
    }

    /**
     * The organization with its branches, current subscription and member counts.
     */
    public function detail(Organization $organization): Organization
    {
        $organization
            ->load(['branches' => fn ($query) => $query->withCount(['teachers']), 'currentSubscription.plan'])
            ->loadCount([
                'branches',
                'teachers',
                'admins',
                'pendingJoinRequests',
                'pendingPlanRequests',
                'pendingPaceRequests',
                'pendingTeachingAssignmentRequests',
                'pendingStudentChangeRequests',
            ]);

        // Students across every section, for the plan's student cap, and per branch.
        $organization->setAttribute('students_count', $organization->studentsQuery()->count());

        $perBranch = $organization->studentsQuery()
            ->join('sections', 'sections.id', '=', 'students.section_id')
            ->join('grade_levels', 'grade_levels.id', '=', 'sections.grade_level_id')
            ->groupBy('grade_levels.branch_id')
            ->selectRaw('grade_levels.branch_id as branch_id, count(*) as total')
            ->pluck('total', 'branch_id');

        foreach ($organization->branches as $branch) {
            $branch->setAttribute('students_count', (int) ($perBranch[$branch->id] ?? 0));
        }

        return $organization;
    }

    /**
     * Find an organization by the public code teachers type in, with its branches.
     */
    public function lookup(string $code): ?Organization
    {
        return Organization::query()
            ->where('code', Organization::normalizeCode($code))
            ->with('branches')
            ->first();
    }

    /**
     * Add a branch. Admins are held to their plan's branch limit.
     *
     * @param  array<string, mixed>  $data  name, address?, is_main?
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function createBranch(Organization $organization, array $data, bool $enforceLimit = true): Branch
    {
        if ($enforceLimit) {
            $limit = $organization->currentSubscription()->with('plan')->first()?->plan?->max_branches;

            if ($limit !== null && $organization->branches()->count() >= $limit) {
                throw ValidationException::withMessages([
                    'name' => [sprintf(
                        'Your plan allows up to %d %s. Contact %s to upgrade.',
                        $limit,
                        $limit === 1 ? 'branch' : 'branches',
                        config('saas.support_email')
                    )],
                ]);
            }
        }

        $isMain = ! empty($data['is_main']) || ! $organization->branches()->where('is_main', true)->exists();

        if ($isMain) {
            $organization->branches()->update(['is_main' => false]);
        }

        $branch = $organization->branches()->create([
            'name' => trim((string) $data['name']),
            'code' => Branch::codeFor((string) $data['name'], $organization->id),
            'address' => $data['address'] ?? null,
            'is_main' => $isMain,
        ]);

        return $branch->loadCount('teachers');
    }

    /**
     * Rename a branch or make it the main one.
     *
     * @param  array<string, mixed>  $data  name?, address?, is_main?
     */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            if (! empty($data['is_main'])) {
                Branch::query()->where('organization_id', $branch->organization_id)->update(['is_main' => false]);
                $branch->is_main = true;
            }

            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $branch->name = trim((string) $data['name']);
                $branch->code = Branch::codeFor($branch->name, $branch->organization_id, $branch->id);
            }

            if (array_key_exists('address', $data)) {
                $branch->address = $data['address'];
            }

            $branch->save();

            return $branch->loadCount('teachers');
        });
    }

    /**
     * Remove a branch. The main branch and branches that still have members cannot go.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function deleteBranch(Branch $branch): void
    {
        if ($branch->is_main) {
            throw ValidationException::withMessages([
                'branch' => ['The main branch cannot be removed. Make another branch the main one first.'],
            ]);
        }

        $members = $branch->users()->count();

        if ($members > 0) {
            throw ValidationException::withMessages([
                'branch' => [sprintf(
                    'This branch still has %d %s. Move them to another branch first.',
                    $members,
                    $members === 1 ? 'member' : 'members'
                )],
            ]);
        }

        $branch->delete();
    }
}
