<?php

namespace Database\Factories;

use App\Enums\JoinRequestStatus;
use App\Models\Branch;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JoinRequest>
 */
class JoinRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\JoinRequest>
     */
    protected $model = JoinRequest::class;

    /**
     * Define the model's default state: a pending request from a teacher
     * without an organization for the organization's main branch.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'user_id' => User::factory()->unassigned(),
            'organization_id' => Organization::factory(),
            'branch_id' => fn (array $attributes) => Branch::query()
                ->where('organization_id', $attributes['organization_id'])
                ->orderByDesc('is_main')
                ->value('id'),
            'requested_role' => 'teacher',
            'status' => JoinRequestStatus::Pending,
            'message' => fake()->optional()->sentence(),
        ];
    }
}
