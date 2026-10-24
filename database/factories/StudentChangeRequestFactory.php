<?php

namespace Database\Factories;

use App\Enums\StudentChangeRequestStatus;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentChangeRequest>
 */
class StudentChangeRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\StudentChangeRequest>
     */
    protected $model = StudentChangeRequest::class;

    /**
     * Define the model's default state: a pending proposal to correct a
     * student's last name, by a teacher of the student's branch.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'student_id' => Student::factory(),
            'requested_by' => function (array $attributes) {
                $branch = Student::query()->whereKey($attributes['student_id'])->first()->section->gradeLevel->branch;

                return User::factory()->inOrganization($branch->organization, $branch)->create()->id;
            },
            'organization_id' => fn (array $attributes) => User::query()->whereKey($attributes['requested_by'])->value('organization_id'),
            'changes' => ['last_name' => fake()->lastName()],
            'status' => StudentChangeRequestStatus::Pending,
        ];
    }
}
