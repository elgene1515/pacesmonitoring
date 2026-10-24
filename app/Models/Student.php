<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\OwnedByUser;
use App\Services\StudentEnrollmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * A student enrolled in one section and in the subjects they take (each
 * student has their own list, so two students of a section may differ).
 * Shared by the teachers who teach one of those subjects in the section;
 * `created_by` only records who registered them.
 */
class Student extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'extension',
        'birth_date',
        'age',
        'sex',
        // Optional contact, emergency and health details (see App\Support\StudentDetails).
        'email',
        'mobile',
        'address',
        'guardian_name',
        'guardian_relationship',
        'guardian_phone',
        'guardian_email',
        'blood_type',
        'medical_notes',
        'section_id',
        'school_year_id',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'birth_date' => 'date',
        'age' => 'integer',
        'sex' => Sex::class,
        'section_id' => 'integer',
        'school_year_id' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * Every student starts their enrollment history with the section they
     * were enrolled in (also for factory- and seeder-made students); moves
     * add to it through StudentService.
     */
    protected static function booted(): void
    {
        static::created(function (Student $student): void {
            if ($student->section_id === null) {
                return;
            }

            app(StudentEnrollmentService::class)->open(
                $student,
                $student->section()->with('gradeLevel')->firstOrFail(),
                $student->created_by === null ? null : $student->creator()->first()
            );
        });
    }

    /**
     * Full name in "Last, First Middle Ext" form (empty parts omitted).
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(function (): string {
            $given = implode(' ', array_filter(
                [$this->first_name, $this->middle_name, $this->extension],
                static fn ($part): bool => $part !== null && trim((string) $part) !== ''
            ));

            $given = trim($given);
            $last = trim((string) $this->last_name);

            if ($last === '') {
                return $given;
            }

            return $given === '' ? $last : "{$last}, {$given}";
        });
    }

    /**
     * Only students who take a subject the given teacher teaches in the student's section.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas('section.teachingAssignments', fn (Builder $assignment) => $assignment
            ->where('teaching_assignments.teacher_id', $id)
            ->whereExists(fn ($enrolled) => $enrolled
                ->selectRaw('1')
                ->from('student_subjects')
                ->whereColumn('student_subjects.student_id', 'students.id')
                ->whereColumn('student_subjects.subject_id', 'teaching_assignments.subject_id')));
    }

    /**
     * Only students enrolled in the given section.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function scopeInSection(Builder $query, Section|int $section): Builder
    {
        return $query->where($this->qualifyColumn('section_id'), $section instanceof Section ? $section->getKey() : $section);
    }

    /**
     * Only students of the given branch.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->whereHas('section', fn (Builder $section) => $section->forBranch($branch));
    }

    /**
     * Only students of the given organization (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        return $query->whereHas('section', fn (Builder $section) => $section->forOrganization($organization));
    }

    /**
     * The section (class) the student is enrolled in.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The grade level of the student's section.
     */
    public function gradeLevel(): HasOneThrough
    {
        return $this->hasOneThrough(
            GradeLevel::class,
            Section::class,
            'id',
            'id',
            'section_id',
            'grade_level_id'
        );
    }

    /**
     * The subjects the student takes (all of them belong to the section's grade level).
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'student_subjects')->withTimestamps();
    }

    /**
     * The school year the student was registered in.
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * The admin or teacher who registered the student.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * PACEs assigned to the student.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PaceAssignment::class);
    }

    /**
     * Pending and past proposals by teachers to change the student's details.
     */
    /**
     * Where the student has been enrolled over time, latest stay first.
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class)->orderByDesc('started_on')->orderByDesc('id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(StudentChangeRequest::class);
    }
}
