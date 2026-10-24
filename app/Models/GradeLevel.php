<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A grade level of a branch under one school year ("Grade 7, 2026-2027").
 * It defines the subjects every student of the grade must finish; its
 * sections are the classes students are enrolled in.
 */
class GradeLevel extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'branch_id',
        'school_year_id',
        'created_by',
        'name',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'branch_id' => 'integer',
        'school_year_id' => 'integer',
        'created_by' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Only grade levels the given teacher teaches at least one subject in
     * (through a teaching assignment on one of its sections).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas('sections.teachingAssignments', fn (Builder $assignment) => $assignment->where('teacher_id', $id));
    }

    /**
     * Only grade levels of the given branch.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->where($this->qualifyColumn('branch_id'), $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    /**
     * Only grade levels of the given organization's branches (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('branch', fn (Builder $branch) => $branch->where('organization_id', $id));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The school year this grade level (and its subjects) is registered under.
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * The admin who set the grade level up.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Subjects every student of this grade level takes, in display order.
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The grade level's classes, in display order.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Students enrolled in any of this grade level's sections.
     */
    public function students(): HasManyThrough
    {
        return $this->hasManyThrough(Student::class, Section::class);
    }

    /**
     * Every PACE record filed under one of this grade level's subjects.
     */
    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(PaceAssignment::class, Subject::class);
    }
}
