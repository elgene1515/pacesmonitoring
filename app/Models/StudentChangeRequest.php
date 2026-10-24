<?php

namespace App\Models;

use App\Enums\StudentChangeRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's proposed edit to a student's details, applied only once a
 * school admin accepts it. `changes` holds just the fields being changed.
 */
class StudentChangeRequest extends Model
{
    use HasFactory;

    /**
     * The student fields a teacher may propose a change to. `age` is derived
     * from the birth date when the change is applied.
     *
     * @var array<int, string>
     */
    public const CHANGEABLE_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'extension',
        'birth_date',
        'sex',
        // The optional details (App\Support\StudentDetails::FIELDS).
        'student_number',
        'email',
        'mobile',
        'address',
        'guardian_name',
        'guardian_relationship',
        'guardian_phone',
        'guardian_email',
        'blood_type',
        'medical_notes',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'student_id',
        'organization_id',
        'requested_by',
        'changes',
        'status',
        'decline_reason',
        'decided_by',
        'decided_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'student_id' => 'integer',
        'organization_id' => 'integer',
        'requested_by' => 'integer',
        'changes' => 'array',
        'status' => StudentChangeRequestStatus::class,
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', StudentChangeRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === StudentChangeRequestStatus::Pending;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The teacher who proposed the change.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
