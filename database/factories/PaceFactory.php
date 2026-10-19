<?php

namespace Database\Factories;

use App\Models\Pace;
use App\Models\User;
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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'number' => (string) fake()->unique()->numberBetween(1001, 99999),
            'created_by' => User::factory(),
        ];
    }
}
