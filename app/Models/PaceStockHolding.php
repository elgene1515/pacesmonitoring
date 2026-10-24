<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many booklets of one PACE a teacher currently has. The branch's own
 * pool lives on the PACE itself (`pool_stock`).
 */
class PaceStockHolding extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'pace_id',
        'user_id',
        'quantity',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'pace_id' => 'integer',
        'user_id' => 'integer',
        'quantity' => 'integer',
    ];

    /**
     * Only the given teacher's holdings.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceStockHolding>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceStockHolding>
     */
    public function scopeForTeacher(Builder $query, User|int $teacher): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $teacher instanceof User ? $teacher->getKey() : $teacher);
    }

    public function pace(): BelongsTo
    {
        return $this->belongsTo(Pace::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
