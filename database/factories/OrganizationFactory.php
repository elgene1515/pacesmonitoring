<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Organization>
     */
    protected $model = Organization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->unique()->company().' School',
            'code' => Organization::generateCode(),
            'address' => fake()->optional()->address(),
            'contact_email' => fake()->safeEmail(),
            'created_by' => null,
        ];
    }

    /**
     * Every organization gets a main branch and an active subscription, so a
     * freshly made organization is immediately usable in tests.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            if (! $organization->branches()->exists()) {
                Branch::factory()->create(['organization_id' => $organization->id]);
            }

            if (! $organization->subscriptions()->exists()) {
                Subscription::factory()->create(['organization_id' => $organization->id]);
            }
        });
    }
}
