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
            'branch_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('branch_id'),
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
     * A request for a PACE already in the branch's catalog, filed by a teacher
     * of that branch.
     *
     * @return static
     */
    public function forPace(Pace $pace, ?User $teacher = null)
    {
        return $this->state(function (array $attributes) use ($pace, $teacher) {
            $branch = $pace->branch;
            $teacher ??= User::factory()->inOrganization($branch->organization, $branch)->create();

            return [
                'user_id' => $teacher->id,
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'pace_id' => $pace->id,
                'pace_number' => $pace->number,
                'pace_name' => $pace->name,
            ];
        });
    }
}
