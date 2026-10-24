<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school year of an organization, e.g. "2026-2027", registered by one of
 * its admins so every teacher of the school works from the same list. One of
 * them is the organization's default ("current"); a teacher may pick a
 * different one as their own current year (User::currentSchoolYear()).
 */
class SchoolYear extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * Label format, e.g. 2026-2027.
     */
    public const NAME_PATTERN = '/^\d{4}-\d{4}$/';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    /**
     * Only the school years of the given organization (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SchoolYear>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\SchoolYear>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('organization_id'), $id);
    }

    /**
     * The school years visible to a user: those of their organization. Kept
     * under the `ownedBy` name so owner-scoped route binding (OwnedByUser)
     * and the services work unchanged.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SchoolYear>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\SchoolYear>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $organizationId = $user instanceof User
            ? $user->organization_id
            : User::query()->whereKey($user)->value('organization_id');

        return $this->scopeForOrganization($query, $organizationId === null ? null : (int) $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The admin who registered this school year.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Grade levels (with their subjects) registered under this school year.
     */
    public function gradeLevels(): HasMany
    {
        return $this->hasMany(GradeLevel::class);
    }

    /**
     * Students registered during this school year.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * PACE records filed under this school year (matched by label). Limited to
     * the records one teacher is in charge of when a user is given, otherwise
     * to every student of the organization.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function assignmentsQuery(User|int|null $user = null): Builder
    {
        $query = PaceAssignment::query()->where('pace_assignments.school_year', $this->name);

        if ($user !== null) {
            return $query->ownedBy($user);
        }

        return $query->forOrganization($this->organization_id);
    }
}
