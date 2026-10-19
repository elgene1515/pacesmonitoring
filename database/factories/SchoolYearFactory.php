<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SchoolYear>
 */
class SchoolYearFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\SchoolYear>
     */
    protected $model = SchoolYear::class;

    /**
     * Define the model's default state. The school year belongs to the
     * registering user's organization (a fresh one when the user has none).
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $start = fake()->unique()->numberBetween(2000, 2099);

        return [
            'user_id' => User::factory()->admin(),
            'organization_id' => function (array $attributes) {
                return User::query()->whereKey($attributes['user_id'])->value('organization_id')
                    ?? Organization::factory()->create()->id;
            },
            'name' => sprintf('%d-%d', $start, $start + 1),
            'start_date' => sprintf('%d-06-01', $start),
            'end_date' => sprintf('%d-05-31', $start + 1),
            'is_current' => false,
        ];
    }

    /**
     * Flag the school year as the organization's default.
     *
     * @return static
     */
    public function current()
    {
        return $this->state(fn (array $attributes) => ['is_current' => true]);
    }
}
