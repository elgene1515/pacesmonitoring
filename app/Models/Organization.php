<?php

namespace App\Models;

use App\Enums\JoinRequestStatus;
use App\Enums\PaceRequestStatus;
use App\Enums\PlanRequestStatus;
use App\Enums\Role;
use App\Enums\StudentChangeRequestStatus;
use App\Enums\TeachingAssignmentRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A school: the tenant every admin, teacher and school year belongs to.
 */
class Organization extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Characters used in public codes (no 0/O or 1/I, which are easy to misread).
     */
    protected const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'contact_email',
        'contact_phone',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_by' => 'integer',
    ];

    /**
     * A new, unused public code such as "SCH-7F3K2A".
     */
    public static function generateCode(): string
    {
        do {
            $suffix = '';

            for ($i = 0; $i < 6; $i++) {
                $suffix .= static::CODE_ALPHABET[random_int(0, strlen(static::CODE_ALPHABET) - 1)];
            }

            $code = 'SCH-'.$suffix;
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Normalise a code typed by a user ("sch-7f3k2a " -> "SCH-7F3K2A").
     */
    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    /**
     * The admin who registered the organization.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->orderByDesc('is_main')->orderBy('name');
    }

    public function mainBranch(): HasOne
    {
        return $this->hasOne(Branch::class)->where('is_main', true);
    }

    /**
     * Every member (admins and teachers).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', Role::Teacher->value);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', Role::Admin->value);
    }

    public function schoolYears(): HasMany
    {
        return $this->hasMany(SchoolYear::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderByDesc('starts_at')->orderByDesc('id');
    }

    /**
     * The subscription in force: the most recently started one.
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany(['starts_at' => 'max', 'id' => 'max']);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(JoinRequest::class);
    }

    public function pendingJoinRequests(): HasMany
    {
        return $this->hasMany(JoinRequest::class)->where('status', JoinRequestStatus::Pending->value);
    }

    /**
     * Plan change / cancellation requests, pending first.
     */
    public function planRequests(): HasMany
    {
        return $this->hasMany(PlanRequest::class)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');
    }

    public function pendingPlanRequests(): HasMany
    {
        return $this->hasMany(PlanRequest::class)->where('status', PlanRequestStatus::Pending->value);
    }

    /**
     * The teachers' PACE stock requests to the school's admins.
     */
    public function paceRequests(): HasMany
    {
        return $this->hasMany(PaceRequest::class);
    }

    public function pendingPaceRequests(): HasMany
    {
        return $this->hasMany(PaceRequest::class)->where('status', PaceRequestStatus::Pending->value);
    }

    /**
     * Teachers' requests for open section + subject slots.
     */
    public function teachingAssignmentRequests(): HasMany
    {
        return $this->hasMany(TeachingAssignmentRequest::class);
    }

    public function pendingTeachingAssignmentRequests(): HasMany
    {
        return $this->hasMany(TeachingAssignmentRequest::class)->where('status', TeachingAssignmentRequestStatus::Pending->value);
    }

    /**
     * Teachers' proposals to change a student's details.
     */
    public function studentChangeRequests(): HasMany
    {
        return $this->hasMany(StudentChangeRequest::class);
    }

    public function pendingStudentChangeRequests(): HasMany
    {
        return $this->hasMany(StudentChangeRequest::class)->where('status', StudentChangeRequestStatus::Pending->value);
    }

    /**
     * Every student enrolled in one of the organization's sections. One row
     * per student, so the count is the real enrollment (the plan limit).
     */
    public function studentsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Student::query()->forOrganization($this);
    }

    /**
     * Every grade level of the organization's branches.
     */
    public function gradeLevelsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return GradeLevel::query()->forOrganization($this);
    }

    /**
     * The PACE catalogs of every branch.
     */
    public function pacesQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Pace::query()->forOrganization($this);
    }

    /**
     * The main branch's address, from the loaded branches when available.
     */
    public function mainBranchAddress(): ?string
    {
        $branches = $this->relationLoaded('branches') ? $this->branches : $this->branches()->get();
        $main = $branches->firstWhere('is_main', true) ?? $branches->first();

        return $main?->address ?: null;
    }

    /**
     * Whether members may still add and change records under the current subscription.
     */
    public function allowsWrites(): bool
    {
        $subscription = $this->relationLoaded('currentSubscription')
            ? $this->currentSubscription
            : $this->currentSubscription()->first();

        return $subscription !== null && $subscription->allowsWrites();
    }
}
