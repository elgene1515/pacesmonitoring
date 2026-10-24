<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A class under a grade level ("Grade 7 – Section 1"): the roster students
 * are enrolled in. Its subjects are the grade level's; who teaches each of
 * them to this section is a teaching assignment.
 */
class Section extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'grade_level_id',
        'name',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'grade_level_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Only sections the given teacher teaches at least one subject in.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Section>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Section>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas('teachingAssignments', fn (Builder $assignment) => $assignment->where('teacher_id', $id));
    }

    /**
     * Only sections of grade levels in the given branch.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Section>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Section>
     */
    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $query->whereHas('gradeLevel', fn (Builder $level) => $level->where('branch_id', $id));
    }

    /**
     * Only sections of the given organization (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Section>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Section>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        return $query->whereHas('gradeLevel', fn (Builder $level) => $level->forOrganization($organization));
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /**
     * Students enrolled in this section.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    /**
     * The subjects this section offers (a subset of its grade level's).
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'section_subjects')->withTimestamps();
    }

    /**
     * The subjects this section offers, in display order.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Subject>
     */
    public function subjectsQuery(): Builder
    {
        return Subject::query()
            ->whereIn('subjects.id', DB::table('section_subjects')->where('section_id', $this->id)->select('subject_id'))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Whether the section offers a subject.
     */
    public function offers(Subject|int $subject): bool
    {
        return $this->subjects()->whereKey($subject instanceof Subject ? $subject->getKey() : $subject)->exists();
    }

    /**
     * The teacher assigned to a subject for this section, if any.
     */
    public function teacherFor(Subject|int $subject): ?User
    {
        $subjectId = $subject instanceof Subject ? $subject->getKey() : $subject;

        return $this->teachingAssignments()->where('subject_id', $subjectId)->first()?->teacher;
    }
}
