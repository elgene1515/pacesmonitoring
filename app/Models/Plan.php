<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription tier (Starter, Growth, Multi-campus, ...). Limits are
 * enforced when an admin adds a branch or admits a teacher.
 */
class Plan extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'max_branches',
        'max_admins',
        'max_teachers',
        'max_students',
        'price_per_year',
        'currency',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'max_branches' => 'integer',
        'max_admins' => 'integer',
        'max_teachers' => 'integer',
        'max_students' => 'integer',
        'price_per_year' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function planRequests(): HasMany
    {
        return $this->hasMany(PlanRequest::class);
    }
}
