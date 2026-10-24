<?php

namespace Database\Factories;

use App\Enums\PaceStockMovementType;
use App\Models\Pace;
use App\Models\PaceStockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaceStockMovement>
 */
class PaceStockMovementFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\PaceStockMovement>
     */
    protected $model = PaceStockMovement::class;

    /**
     * Define the model's default state: a delivery received into the pool.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'pace_id' => Pace::factory(),
            'type' => PaceStockMovementType::Received,
            'quantity' => fake()->numberBetween(1, 30),
            'from_user_id' => null,
            'to_user_id' => null,
            'performed_by' => null,
            'pace_request_id' => null,
            'note' => null,
        ];
    }
}
