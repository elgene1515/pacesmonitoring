<?php

namespace App\Models;

use App\Enums\PaceRequestStatus;
use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pace extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'number',
        'stock',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stock' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * withCount() / loadCount() spec for PACE lists: the assignment breakdown
     * plus the number of stock requests still waiting for the admin.
     *
     * @return array<int|string, mixed>
     */
    public static function counts(): array
    {
        return array_merge(PaceAssignment::statusCounts(), ['pendingPaceRequests']);
    }

    /**
     * Only PACEs registered by the given user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where($this->qualifyColumn('created_by'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * True while at least one booklet is on hand.
     */
    public function hasStock(): bool
    {
        return (int) $this->stock > 0;
    }

    /**
     * The user who registered the PACE.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Assignments of this PACE to students.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PaceAssignment::class);
    }

    /**
     * Stock requests the teacher filed for this PACE.
     */
    public function paceRequests(): HasMany
    {
        return $this->hasMany(PaceRequest::class);
    }

    public function pendingPaceRequests(): HasMany
    {
        return $this->hasMany(PaceRequest::class)->where('status', PaceRequestStatus::Pending->value);
    }
}
