<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TeachingAssignment>
 */
class TeachingAssignmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\TeachingAssignment>
     */
    protected $model = TeachingAssignment::class;

    /**
     * Define the model's default state: a teacher of the section's branch
     * assigned a subject of the section's grade level.
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
            'teacher_id' => function (array $attributes) {
                $branch = Section::query()->whereKey($attributes['section_id'])->first()->gradeLevel->branch;

                return User::factory()->inOrganization($branch->organization, $branch)->create()->id;
            },
            'assigned_by' => null,
        ];
    }

    /**
     * Assign the given teacher the given subject in the given section.
     * (Not named `for`: the base factory already has a `for()`.)
     *
     * @return static
     */
    public function assigning(Section $section, Subject $subject, User $teacher)
    {
        return $this->state(fn (array $attributes) => [
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);
    }
}
