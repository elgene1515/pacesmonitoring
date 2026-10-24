<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
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
     * Define the model's default state: a grade level of a branch, registered
     * under the branch's organization's default school year (a new current
     * year when the organization has none yet).
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $number = fake()->unique()->numberBetween(1, 1000);

        return [
            'branch_id' => Branch::factory(),
            'school_year_id' => function (array $attributes) {
                $organizationId = Branch::query()->whereKey($attributes['branch_id'])->value('organization_id');

                return SchoolYear::query()->forOrganization($organizationId)->where('is_current', true)->value('id')
                    ?? SchoolYear::factory()->current()->create([
                        'user_id' => \App\Models\User::factory()->admin()->unassigned()->create()->id,
                        'organization_id' => $organizationId,
                    ])->id;
            },
            'created_by' => null,
            'name' => 'Grade '.$number,
            'sort_order' => $number,
        ];
    }

    /**
     * A grade level of the given branch.
     *
     * @return static
     */
    public function forBranch(Branch $branch)
    {
        return $this->state(fn (array $attributes) => ['branch_id' => $branch->id]);
    }
}
