<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\User;
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
     * Define the model's default state.
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
            'grade_level_id' => GradeLevel::factory(),
            'created_by' => User::factory(),
        ];
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
