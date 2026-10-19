<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One subscription period of an organization.
 */
class Subscription extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'notes',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'organization_id' => 'integer',
        'plan_id' => 'integer',
        'status' => SubscriptionStatus::class,
        'starts_at' => 'date',
        'ends_at' => 'date',
        'created_by' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The stored status, except that a trial / active / past-due subscription
     * whose end date has passed counts as expired.
     */
    public function effectiveStatus(): SubscriptionStatus
    {
        $status = $this->status instanceof SubscriptionStatus ? $this->status : SubscriptionStatus::from((string) $this->status);

        if ($status->allowsWrites() && $this->ends_at !== null && $this->ends_at->lt(Carbon::today())) {
            return SubscriptionStatus::Expired;
        }

        return $status;
    }

    public function allowsWrites(): bool
    {
        return $this->effectiveStatus()->allowsWrites();
    }

    /**
     * Whole days until the end date (negative once passed), or null when open-ended.
     */
    public function daysLeft(): ?int
    {
        if ($this->ends_at === null) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->ends_at, false);
    }
}
