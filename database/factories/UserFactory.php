<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state: a teacher who is a member of their
     * own, freshly made organization (main branch, active subscription), so
     * two factory users are strangers to each other unless placed in the
     * same organization with inOrganization().
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
            'role' => Role::Teacher,
            'organization_id' => Organization::factory(),
            'branch_id' => fn (array $attributes) => $attributes['organization_id'] === null
                ? null
                : Branch::query()->where('organization_id', $attributes['organization_id'])->orderByDesc('is_main')->value('id'),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return static
     */
    public function unverified()
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * The platform owner (no organization).
     *
     * @return static
     */
    public function owner()
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Owner,
            'organization_id' => null,
            'branch_id' => null,
        ]);
    }

    /**
     * A school administrator (of their own organization unless placed elsewhere).
     *
     * @return static
     */
    public function admin()
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Admin,
        ]);
    }

    /**
     * A user who has not joined (or registered) an organization yet.
     *
     * @return static
     */
    public function unassigned()
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => null,
            'branch_id' => null,
        ]);
    }

    /**
     * A member of the given organization (its main branch unless a branch is given).
     *
     * @return static
     */
    public function inOrganization(Organization $organization, ?Branch $branch = null)
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
            'branch_id' => $branch?->id ?? $organization->branches()->orderByDesc('is_main')->value('id'),
        ]);
    }
}
