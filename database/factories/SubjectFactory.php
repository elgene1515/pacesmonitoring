<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * Students already in the grade level take a new subject by default (a fixture convenience;
     * the real app leaves the choice to the admin).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Subject $subject): void {
            $ids = Student::query()
                ->whereHas('section', fn ($section) => $section->where('grade_level_id', $subject->grade_level_id))
                ->pluck('id')
                ->all();

            $subject->students()->syncWithoutDetaching($ids);
            $subject->sections()->syncWithoutDetaching(
                \App\Models\Section::query()->where('grade_level_id', $subject->grade_level_id)->pluck('id')->all()
            );
        });
    }

    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Subject>
     */
    protected $model = Subject::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'grade_level_id' => GradeLevel::factory(),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
