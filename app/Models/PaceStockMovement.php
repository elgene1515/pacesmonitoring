<?php

namespace App\Models;

use App\Enums\PaceStockMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the stock ledger: booklets received into the branch's pool,
 * handed to a teacher, returned, or a pool correction.
 */
class PaceStockMovement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'pace_id',
        'type',
        'quantity',
        'from_user_id',
        'to_user_id',
        'performed_by',
        'pace_request_id',
        'note',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'pace_id' => 'integer',
        'type' => PaceStockMovementType::class,
        'quantity' => 'integer',
        'from_user_id' => 'integer',
        'to_user_id' => 'integer',
        'performed_by' => 'integer',
        'pace_request_id' => 'integer',
    ];

    public function pace(): BelongsTo
    {
        return $this->belongsTo(Pace::class);
    }

    /**
     * The teacher the booklets came from (a return).
     */
    public function fromTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * The teacher the booklets went to (a distribution).
     */
    public function toTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * The stock request this move fulfilled, when there was one.
     */
    public function paceRequest(): BelongsTo
    {
        return $this->belongsTo(PaceRequest::class);
    }
}
