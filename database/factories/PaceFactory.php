<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Pace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pace>
 */
class PaceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Pace>
     */
    protected $model = Pace::class;

    /**
     * Define the model's default state: a catalog entry of a branch with an
     * empty pool.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => ucfirst(fake()->words(3, true)),
            'number' => (string) fake()->unique()->numberBetween(1001, 99999),
            'pool_stock' => 0,
            'created_by' => null,
        ];
    }

    /**
     * A PACE in the given branch's catalog.
     *
     * @return static
     */
    public function forBranch(Branch $branch)
    {
        return $this->state(fn (array $attributes) => ['branch_id' => $branch->id]);
    }

    /**
     * With booklets in the branch's pool.
     *
     * @return static
     */
    public function withPoolStock(int $quantity)
    {
        return $this->state(fn (array $attributes) => ['pool_stock' => $quantity]);
    }
}
