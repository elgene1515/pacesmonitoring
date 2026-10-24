<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Section>
 */
class SectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Section>
     */
    protected $model = Section::class;

    /**
     * A new section offers every subject of its grade level (like the app does by default).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Section $section): void {
            $section->subjects()->syncWithoutDetaching(
                \App\Models\Subject::query()->where('grade_level_id', $section->grade_level_id)->pluck('id')->all()
            );
        });
    }

    /**
     * Define the model's default state: the next section of a grade level.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'grade_level_id' => GradeLevel::factory(),
            'name' => function (array $attributes) {
                $number = Section::query()->where('grade_level_id', $attributes['grade_level_id'])->count() + 1;

                return 'Section '.$number;
            },
            'sort_order' => fn (array $attributes) => (int) filter_var($attributes['name'], FILTER_SANITIZE_NUMBER_INT),
        ];
    }
}
