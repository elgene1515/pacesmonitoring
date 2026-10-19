<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GradeLevel>
 */
class GradeLevelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\GradeLevel>
     */
    protected $model = GradeLevel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $number = fake()->unique()->numberBetween(1, 1000);

        return [
            'user_id' => User::factory(),
            // Registered under one of the owner's organization's school years
            // (its default one when it has any, otherwise a new current year).
            'school_year_id' => function (array $attributes) {
                $user = User::query()->find($attributes['user_id']);
                $organizationId = $user?->organization_id;

                return SchoolYear::query()->forOrganization($organizationId)->where('is_current', true)->value('id')
                    ?? SchoolYear::factory()->current()->create([
                        'user_id' => $attributes['user_id'],
                        'organization_id' => $organizationId ?? \App\Models\Organization::factory()->create()->id,
                    ])->id;
            },
            'name' => 'Grade '.$number,
            'sort_order' => $number,
        ];
    }
}
