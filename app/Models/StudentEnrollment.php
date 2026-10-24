<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stay of a student in a section: from the day they were enrolled or
 * moved there until the day they moved on (`ended_on` null = where they are
 * now). The section, grade level, school year and branch names are copied
 * onto the row so the history outlives the curriculum rows.
 */
class StudentEnrollment extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'student_id',
        'section_id',
        'grade_level_id',
        'school_year_id',
        'branch_id',
        'section_name',
        'grade_level_name',
        'school_year_name',
        'branch_name',
        'started_on',
        'ended_on',
        'moved_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_on' => 'date',
        'ended_on' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Who enrolled or moved the student here.
     */
    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    /**
     * The stay that is still going on.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null;
    }
}
