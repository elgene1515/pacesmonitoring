<?php

namespace App\Models;

use App\Enums\PaceRequestStatus;
use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PACE in a branch's catalog ("1001 – Math"), shared by the branch's
 * teachers and admin. Numbers repeat across subjects (Math 1001 and English
 * 1001 are different booklets), so an entry is identified by number + name.
 * `pool_stock` is the branch's pool of booklets; what each teacher has is a
 * PaceStockHolding.
 */
class Pace extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'branch_id',
        'name',
        'number',
        'pool_stock',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'branch_id' => 'integer',
        'pool_stock' => 'integer',
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
     * The catalog the given teacher works from: their branch's.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        $branchId = $user instanceof User
            ? $user->branch_id
            : User::query()->whereKey($user)->value('branch_id');

        if ($branchId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('branch_id'), (int) $branchId);
    }

    /**
     * Only the given branch's catalog.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function scopeForBranch(Builder $query, Branch|int $branch): Builder
    {
        return $query->where($this->qualifyColumn('branch_id'), $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    /**
     * Only the catalogs of the given organization's branches (null matches nothing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function scopeForOrganization(Builder $query, Organization|int|null $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('branch', fn (Builder $branch) => $branch->where('organization_id', $id));
    }

    /**
     * True while the branch's pool has at least one booklet.
     */
    public function hasPoolStock(): bool
    {
        return (int) $this->pool_stock > 0;
    }

    /**
     * How many booklets the given teacher has of this PACE.
     */
    public function stockFor(User|int $teacher): int
    {
        return (int) $this->holdings()->forTeacher($teacher)->value('quantity');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Who registered the PACE in the catalog (an admin, or the teacher whose request added it).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Each teacher's booklets of this PACE.
     */
    public function holdings(): HasMany
    {
        return $this->hasMany(PaceStockHolding::class);
    }

    /**
     * The stock ledger of this PACE, newest first.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PaceStockMovement::class)->orderByDesc('id');
    }

    /**
     * Assignments of this PACE to students.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PaceAssignment::class);
    }

    /**
     * Stock requests teachers filed for this PACE.
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
