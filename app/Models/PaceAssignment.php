<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaceAssignment extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * Minimum score considered a pass (mirrors resources/js/models/PaceAssignment.js).
     */
    public const PASSING_SCORE = 80;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'student_id',
        'subject_id',
        'pace_id',
        'school_year',
        'quarter',
        'issued_date',
        'date_tested',
        'score',
        'remarks',
        'retake',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'student_id' => 'integer',
        'subject_id' => 'integer',
        'pace_id' => 'integer',
        'quarter' => 'integer',
        'issued_date' => 'date',
        'date_tested' => 'date',
        'score' => 'float',
        'retake' => 'boolean',
    ];

    /**
     * Only assignments the given teacher is in charge of: the record's subject
     * is one they are assigned to teach in the student's section.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas('student.section.teachingAssignments', function (Builder $assignment) use ($id) {
            $assignment
                ->where('teaching_assignments.teacher_id', $id)
                ->whereColumn('teaching_assignments.subject_id', 'pace_assignments.subject_id');
        });
    }

    /**
     * Only assignments of students in the given organization (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        return $query->whereHas('student', fn (Builder $student) => $student->forOrganization($organization));
    }

    /**
     * Only assignments with a completed test: either a test date or a score
     * recorded (a test can be scored later than it's taken).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function scopeTested(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotNull('date_tested')->orWhereNotNull('score');
        });
    }

    /**
     * Only assignments that have been issued but not yet tested.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function scopeIssuedOnly(Builder $query): Builder
    {
        return $query->whereNotNull('issued_date')->whereNull('date_tested')->whereNull('score');
    }

    /**
     * Only assignments that have been neither issued nor tested yet.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function scopeAssignedOnly(Builder $query): Builder
    {
        return $query->whereNull('issued_date')->whereNull('date_tested')->whereNull('score');
    }

    /**
     * withCount() / loadCount() spec for a parent's `assignments` relation: the
     * total (`assignments_count`) plus one count per status (`tested_count`,
     * `issued_count`, `assigned_count`), which always add up to the total.
     *
     * @return array<int|string, mixed>
     */
    public static function statusCounts(): array
    {
        return [
            'assignments',
            'assignments as tested_count' => fn (Builder $query) => $query->tested(),
            'assignments as issued_count' => fn (Builder $query) => $query->issuedOnly(),
            'assignments as assigned_count' => fn (Builder $query) => $query->assignedOnly(),
        ];
    }

    /**
     * The student this PACE was assigned to.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The subject the PACE was assigned under.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * The PACE that was assigned.
     */
    public function pace(): BelongsTo
    {
        return $this->belongsTo(Pace::class);
    }
}
