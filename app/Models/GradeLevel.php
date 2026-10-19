<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class GradeLevel extends Model
{
    use HasFactory, OwnedByUser;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'school_year_id',
        'name',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'school_year_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * The school year this grade level (and its subjects) is registered under.
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * Only grade levels created by the given user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * The user who created this grade level.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Subjects taught at this grade level, in display order.
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Students currently enrolled at this grade level.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Every PACE record filed under one of this grade level's subjects.
     */
    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(PaceAssignment::class, Subject::class);
    }
}
