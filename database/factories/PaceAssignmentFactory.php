<?php

namespace Database\Factories;

use App\Enums\Quarter;
use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaceAssignment>
 */
class PaceAssignmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\PaceAssignment>
     */
    protected $model = PaceAssignment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $startYear = (int) now()->format('Y');
        if ((int) now()->format('n') < 6) {
            $startYear--;
        }

        return [
            'student_id' => Student::factory(),
            // Default to a subject of the student's grade level: one somebody teaches
            // to the student's section, else any of the grade level's, else a new one.
            'subject_id' => function (array $attributes) {
                $sectionId = Student::query()->whereKey($attributes['student_id'])->value('section_id');
                $gradeLevelId = Section::query()->whereKey($sectionId)->value('grade_level_id')
                    ?? GradeLevel::factory()->create()->id;

                return TeachingAssignment::query()->where('section_id', $sectionId)->orderBy('id')->value('subject_id')
                    ?? Subject::query()->where('grade_level_id', $gradeLevelId)->orderBy('sort_order')->orderBy('id')->value('id')
                    ?? Subject::factory()->create(['grade_level_id' => $gradeLevelId])->id;
            },
            // A PACE from the catalog of the student's branch.
            'pace_id' => function (array $attributes) {
                $branchId = GradeLevel::query()
                    ->whereKey(Section::query()->whereKey(Student::query()->whereKey($attributes['student_id'])->value('section_id'))->value('grade_level_id'))
                    ->value('branch_id');

                return Pace::factory()->create($branchId === null ? [] : ['branch_id' => $branchId])->id;
            },
            'school_year' => sprintf('%d-%d', $startYear, $startYear + 1),
            'quarter' => fake()->randomElement(Quarter::values()),
            'issued_date' => null,
            'date_tested' => null,
            'score' => null,
            'remarks' => null,
            'retake' => false,
        ];
    }

    /**
     * The PACE has been issued but not yet tested.
     *
     * @return static
     */
    public function issued()
    {
        return $this->state(fn (array $attributes) => [
            'issued_date' => Carbon::instance(fake()->dateTimeBetween('-60 days', 'now'))->toDateString(),
        ]);
    }

    /**
     * The PACE has been issued and tested with a score.
     *
     * @return static
     */
    public function tested()
    {
        return $this->state(function (array $attributes) {
            $issued = Carbon::instance(fake()->dateTimeBetween('-90 days', '-15 days'));

            return [
                'issued_date' => $issued->toDateString(),
                'date_tested' => $issued->copy()->addDays(fake()->numberBetween(3, 14))->toDateString(),
                'score' => fake()->randomFloat(2, 60, 100),
            ];
        });
    }
}
