<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'grade_level_id',
        'name',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'grade_level_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Only subjects under grade levels created by the given user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Subject>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Subject>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->whereHas('gradeLevel', fn (Builder $level) => $level->ownedBy($user));
    }

    /**
     * The grade level this subject belongs to.
     */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /**
     * PACE assignments recorded under this subject.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PaceAssignment::class);
    }
}
