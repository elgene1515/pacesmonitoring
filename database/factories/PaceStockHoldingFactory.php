<?php

namespace Database\Factories;

use App\Models\Pace;
use App\Models\PaceStockHolding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaceStockHolding>
 */
class PaceStockHoldingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\PaceStockHolding>
     */
    protected $model = PaceStockHolding::class;

    /**
     * Define the model's default state: a teacher of the PACE's branch
     * holding a few booklets.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'pace_id' => Pace::factory(),
            'user_id' => function (array $attributes) {
                $branch = Pace::query()->whereKey($attributes['pace_id'])->first()->branch;

                return User::factory()->inOrganization($branch->organization, $branch)->create()->id;
            },
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
