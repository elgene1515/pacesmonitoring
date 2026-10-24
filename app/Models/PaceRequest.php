<?php

namespace App\Models;

use App\Enums\PaceRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's request to their school admin for PACE booklets, decided by
 * the admin (given / declined) or withdrawn by the teacher.
 */
class PaceRequest extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'branch_id',
        'user_id',
        'pace_id',
        'pace_number',
        'pace_name',
        'quantity',
        'needed_by',
        'message',
        'status',
        'decision_note',
        'decided_by',
        'decided_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'organization_id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'pace_id' => 'integer',
        'quantity' => 'integer',
        'needed_by' => 'date',
        'status' => PaceRequestStatus::class,
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaceRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === PaceRequestStatus::Pending;
    }

    /**
     * True while the request points at a PACE already in the branch's catalog.
     * An unlinked request names a new number: approving it registers the PACE
     * in the catalog as well.
     */
    public function isLinked(): bool
    {
        return $this->pace_id !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /**
     * The branch whose catalog and pool the request is about.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The teacher who asked.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The catalog PACE the stock is for, when the teacher picked one.
     */
    public function pace(): BelongsTo
    {
        return $this->belongsTo(Pace::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
