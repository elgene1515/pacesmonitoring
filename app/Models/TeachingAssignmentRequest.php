<?php

namespace App\Models;

use App\Enums\TeachingAssignmentRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's request to teach a subject to a section, decided by a school
 * admin (accepted creates the teaching assignment) or withdrawn.
 */
class TeachingAssignmentRequest extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'organization_id',
        'section_id',
        'subject_id',
        'message',
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
        'user_id' => 'integer',
        'organization_id' => 'integer',
        'section_id' => 'integer',
        'subject_id' => 'integer',
        'status' => TeachingAssignmentRequestStatus::class,
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TeachingAssignmentRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === TeachingAssignmentRequestStatus::Pending;
    }

    /**
     * The teacher who asked.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
