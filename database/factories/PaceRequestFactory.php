<?php

namespace Database\Factories;

use App\Enums\PaceRequestStatus;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaceRequest>
 */
class PaceRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\PaceRequest>
     */
    protected $model = PaceRequest::class;

    /**
     * Define the model's default state: a teacher's pending request for a PACE
     * they have not registered (no linked PACE).
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => fn (array $attributes) => User::query()->find($attributes['user_id'])?->organization_id
                ?? Organization::factory()->create()->id,
            'pace_id' => null,
            'pace_number' => (string) fake()->numberBetween(1001, 1144),
            'pace_name' => ucfirst(fake()->word()),
            'quantity' => fake()->numberBetween(1, 20),
            'needed_by' => null,
            'message' => fake()->optional()->sentence(),
            'status' => PaceRequestStatus::Pending,
        ];
    }

    /**
     * A request for one of the teacher's registered PACEs (filed by its owner).
     *
     * @return static
     */
    public function forPace(Pace $pace)
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $pace->created_by,
            'organization_id' => $pace->creator?->organization_id,
            'pace_id' => $pace->id,
            'pace_number' => $pace->number,
            'pace_name' => $pace->name,
        ]);
    }
}
