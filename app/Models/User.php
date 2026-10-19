<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable. The role and the organization
     * membership are deliberately left out: they are only ever set by the
     * registration / join / owner flows, never straight from a request body.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'role' => Role::class,
        'organization_id' => 'integer',
        'branch_id' => 'integer',
        'current_school_year_id' => 'integer',
        'left_organization_at' => 'datetime',
    ];

    /**
     * Remember when a member loses their organization, and forget it once they
     * belong to one again. Done here so every path (removal, console commands)
     * is covered.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (! $user->isDirty('organization_id')) {
                return;
            }

            $user->left_organization_at = $user->organization_id === null && $user->getOriginal('organization_id') !== null
                ? now()
                : null;
        });
    }

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'teacher',
    ];

    /* ---------------------------------------------------------------- roles */

    public function roleEnum(): Role
    {
        return $this->role instanceof Role ? $this->role : Role::from((string) ($this->role ?? Role::Teacher->value));
    }

    /**
     * The platform owner: sees every organization, manages plans and subscriptions.
     */
    public function isOwner(): bool
    {
        return $this->roleEnum() === Role::Owner;
    }

    /**
     * A school administrator (manages one organization).
     */
    public function isAdmin(): bool
    {
        return $this->roleEnum() === Role::Admin;
    }

    public function isTeacher(): bool
    {
        return $this->roleEnum() === Role::Teacher;
    }

    public function isViceAdmin(): bool
    {
        return $this->roleEnum() === Role::ViceAdmin;
    }

    /**
     * Platform staff: the owner or a vice admin. Same platform-wide view; a
     * vice admin cannot manage staff, delete organizations or delete plans.
     */
    public function isPlatform(): bool
    {
        return $this->roleEnum()->isPlatform();
    }

    /**
     * Only platform staff (owners and vice admins).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('role'), Role::platform());
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $value = $role instanceof Role ? $role->value : $role;

            if ($this->roleEnum()->value === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only users with the given role(s).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function scopeRole(Builder $query, Role|string ...$roles): Builder
    {
        $values = array_map(static fn (Role|string $role): string => $role instanceof Role ? $role->value : $role, $roles);

        return $query->whereIn($this->qualifyColumn('role'), $values);
    }

    /**
     * Only teachers (the users who own students, PACEs and grade levels).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function scopeTeachers(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('role'), Role::Teacher->value);
    }

    /**
     * Only members of the given organization (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function scopeInOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('organization_id'), $id);
    }

    /* ----------------------------------------------------------- membership */

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * True once the user belongs to an organization. Teachers get there by
     * having a join request accepted; admins by registering their school.
     */
    public function hasOrganization(): bool
    {
        return $this->organization_id !== null;
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(JoinRequest::class);
    }

    /**
     * The teacher's most recent join request (pending, declined, ...).
     */
    public function latestJoinRequest(): HasOne
    {
        return $this->hasOne(JoinRequest::class)->latestOfMany();
    }

    /**
     * The teacher's PACE stock requests to their school's admins.
     */
    public function paceRequests(): HasMany
    {
        return $this->hasMany(PaceRequest::class);
    }

    /* --------------------------------------------------------- school years */

    /**
     * The school years of the user's organization (every member shares the same list).
     */
    public function schoolYears(): HasMany
    {
        return $this->hasMany(SchoolYear::class, 'organization_id', 'organization_id');
    }

    /**
     * The school year the user personally picked as current (teachers only).
     */
    public function currentSchoolYearChoice(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class, 'current_school_year_id');
    }

    /**
     * The school year that counts as "current" for this user: their own pick
     * when it still belongs to their organization, else the organization's
     * default, else the newest one.
     */
    public function currentSchoolYear(): ?SchoolYear
    {
        if (! $this->hasOrganization()) {
            return null;
        }

        $years = SchoolYear::query()->forOrganization($this->organization_id);

        if ($this->current_school_year_id !== null) {
            $own = (clone $years)->find($this->current_school_year_id);

            if ($own !== null) {
                return $own;
            }
        }

        return (clone $years)->where('is_current', true)->first()
            ?? $years->orderByDesc('name')->first();
    }

    /* -------------------------------------------------------------- records */

    /**
     * Grade levels (and, through them, subjects) created by this user.
     */
    public function gradeLevels(): HasMany
    {
        return $this->hasMany(GradeLevel::class);
    }

    /**
     * Students registered by this user.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'created_by');
    }

    /**
     * PACEs registered by this user.
     */
    public function paces(): HasMany
    {
        return $this->hasMany(Pace::class, 'created_by');
    }

    /**
     * PACE assignments recorded for this user's students. Not a direct foreign
     * key: pace_assignments has no owner column, so this joins through students.
     */
    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(PaceAssignment::class, Student::class, 'created_by', 'student_id');
    }
}
