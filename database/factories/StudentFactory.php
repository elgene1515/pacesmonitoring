<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Student>
 */
class StudentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Student>
     */
    protected $model = Student::class;

    /**
     * Define the model's default state: a student enrolled in a section,
     * filed under the section's grade level's school year.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $sex = fake()->randomElement(Sex::cases());
        $gender = $sex === Sex::Male ? 'male' : 'female';
        $birthDate = Carbon::instance(fake()->dateTimeBetween('-18 years', '-5 years'));

        return [
            'first_name' => fake()->firstName($gender),
            'middle_name' => fake()->optional(0.7)->lastName(),
            'last_name' => fake()->lastName(),
            'extension' => fake()->optional(0.1)->randomElement(['Jr.', 'Sr.', 'II', 'III']),
            'birth_date' => $birthDate->toDateString(),
            'age' => $birthDate->age,
            'sex' => $sex,
            'section_id' => Section::factory(),
            'school_year_id' => function (array $attributes) {
                $gradeLevelId = Section::query()->whereKey($attributes['section_id'])->value('grade_level_id');

                return GradeLevel::query()->whereKey($gradeLevelId)->value('school_year_id');
            },
            'created_by' => null,
        ];
    }

    /**
     * A student of the given section.
     *
     * @return static
     */
    public function inSection(Section $section)
    {
        return $this->state(fn (array $attributes) => ['section_id' => $section->id]);
    }

    /**
     * Indicate the student is male.
     *
     * @return static
     */
    public function male()
    {
        return $this->state(fn (array $attributes) => [
            'sex' => Sex::Male,
            'first_name' => fake()->firstNameMale(),
        ]);
    }

    /**
     * A new student takes every subject their section offers (tests and seeders can narrow it).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Student $student): void {
            $student->loadMissing('section');

            $student->subjects()->syncWithoutDetaching($student->section?->subjects()->pluck('subjects.id')->all() ?? []);
        });
    }

    /**
     * Indicate the student is female.
     *
     * @return static
     */
    public function female()
    {
        return $this->state(fn (array $attributes) => [
            'sex' => Sex::Female,
            'first_name' => fake()->firstNameFemale(),
        ]);
    }
}
