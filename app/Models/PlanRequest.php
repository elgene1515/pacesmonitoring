<?php

namespace App\Models;

use App\Enums\PlanRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school admin's request to change the organization's plan (or cancel it),
 * decided by the owner.
 */
class PlanRequest extends Model
{
    use HasFactory;

    public const ACTION_CHANGE = 'change';

    public const ACTION_CANCEL = 'cancel';

    /**
     * @return array<int, string>
     */
    public static function actions(): array
    {
        return [self::ACTION_CHANGE, self::ACTION_CANCEL];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'requested_by',
        'action',
        'plan_id',
        'message',
        'status',
        'decision_note',
        'decided_by',
        'decided_at',
        'subscription_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'organization_id' => 'integer',
        'requested_by' => 'integer',
        'plan_id' => 'integer',
        'status' => PlanRequestStatus::class,
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
        'subscription_id' => 'integer',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PlanRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === PlanRequestStatus::Pending;
    }

    public function isCancellation(): bool
    {
        return $this->action === self::ACTION_CANCEL;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
