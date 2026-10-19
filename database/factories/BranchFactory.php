<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Branch>
     */
    protected $model = Branch::class;

    /**
     * Define the model's default state: the organization's main branch unless
     * it already has one, in which case a secondary campus.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => function (array $attributes) {
                return Branch::query()->where('organization_id', $attributes['organization_id'])->where('is_main', true)->exists()
                    ? fake()->unique()->city().' Campus'
                    : 'Main';
            },
            'code' => fn (array $attributes) => Branch::codeFor($attributes['name'], (int) $attributes['organization_id']),
            'is_main' => fn (array $attributes) => $attributes['name'] === 'Main',
        ];
    }
}
