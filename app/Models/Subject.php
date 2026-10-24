<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject of a grade level, shared by every section of that grade level.
 */
class Subject extends Model
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
     * Only subjects the given teacher is assigned to teach (in any section).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Subject>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Subject>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas('teachingAssignments', fn (Builder $assignment) => $assignment->where('teacher_id', $id));
    }

    /**
     * The grade level this subject belongs to.
     */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /**
     * Who teaches this subject, per section.
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    /**
     * The sections that offer this subject.
     */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'section_subjects')->withTimestamps();
    }

    /**
     * The students who take this subject.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_subjects')->withTimestamps();
    }

    /**
     * PACE assignments recorded under this subject.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PaceAssignment::class);
    }
}
