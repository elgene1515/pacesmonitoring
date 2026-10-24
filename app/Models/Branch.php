<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A branch (campus) of an organization. Teachers join a branch; its grade
 * levels, sections and PACE catalog are the branch's own.
 */
class Branch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address',
        'is_main',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'organization_id' => 'integer',
        'is_main' => 'boolean',
    ];

    /**
     * A short code derived from the name ("North Campus" -> "NORTH-CAMPUS"),
     * made unique within the organization with a numeric suffix if needed.
     */
    public static function codeFor(string $name, int $organizationId, ?int $ignoreId = null): string
    {
        $base = Str::upper(Str::slug($name)) ?: 'BRANCH';
        $base = Str::limit($base, 16, '');
        $code = $base;
        $attempt = 1;

        while (static::query()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $attempt++;
            $code = $base.'-'.$attempt;
        }

        return $code;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Members (teachers and admins) attached to this branch.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'teacher');
    }

    /**
     * The branch's grade levels (each with its subjects and sections).
     */
    public function gradeLevels(): HasMany
    {
        return $this->hasMany(GradeLevel::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The branch's PACE catalog.
     */
    public function paces(): HasMany
    {
        return $this->hasMany(Pace::class);
    }

    /**
     * Every student enrolled in one of the branch's sections.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function studentsQuery(): Builder
    {
        return Student::query()->forBranch($this);
    }
}
