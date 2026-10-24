<?php

namespace Database\Factories;

use App\Enums\TeachingAssignmentRequestStatus;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TeachingAssignmentRequest>
 */
class TeachingAssignmentRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\TeachingAssignmentRequest>
     */
    protected $model = TeachingAssignmentRequest::class;

    /**
     * Define the model's default state: a pending request by a teacher of
     * the section's branch for a subject of the section's grade level.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'section_id' => Section::factory(),
            'subject_id' => function (array $attributes) {
                $gradeLevelId = Section::query()->whereKey($attributes['section_id'])->value('grade_level_id');

                return Subject::factory()->create(['grade_level_id' => $gradeLevelId])->id;
            },
            'user_id' => function (array $attributes) {
                $branch = Section::query()->whereKey($attributes['section_id'])->first()->gradeLevel->branch;

                return User::factory()->inOrganization($branch->organization, $branch)->create()->id;
            },
            'organization_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('organization_id'),
            'message' => fake()->optional()->sentence(),
            'status' => TeachingAssignmentRequestStatus::Pending,
        ];
    }
}
