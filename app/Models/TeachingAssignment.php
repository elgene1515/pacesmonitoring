<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One teacher teaching one subject to one section. The pair (section,
 * subject) is unique: no co-teaching.
 */
class TeachingAssignment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'section_id',
        'subject_id',
        'teacher_id',
        'assigned_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'section_id' => 'integer',
        'subject_id' => 'integer',
        'teacher_id' => 'integer',
        'assigned_by' => 'integer',
    ];

    /**
     * Only the given teacher's assignments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\TeachingAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\TeachingAssignment>
     */
    public function scopeForTeacher(Builder $query, User|int $teacher): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $teacher instanceof User ? $teacher->getKey() : $teacher);
    }

    /**
     * Only assignments in the given organization (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\TeachingAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\TeachingAssignment>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        return $query->whereHas('section', fn (Builder $section) => $section->forOrganization($organization));
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * The admin who made the assignment (null when it came from an accepted request).
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
