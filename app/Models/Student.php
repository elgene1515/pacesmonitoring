<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'extension',
        'birth_date',
        'age',
        'sex',
        'grade_level_id',
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
        'grade_level_id' => 'integer',
        'school_year_id' => 'integer',
        'created_by' => 'integer',
    ];

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
     * Only students registered by the given user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where($this->qualifyColumn('created_by'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * The grade level the student is enrolled in.
     */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /**
     * The school year the student was registered in.
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * The user who registered the student.
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
}
