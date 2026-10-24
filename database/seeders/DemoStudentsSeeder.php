<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\StudentService;
use Database\Seeders\Concerns\DemoSchool;
use Illuminate\Database\Seeder;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Students enrolled in every section of the demo school. Each takes the
 * subjects their section offers, except that some skip Filipino or Science
 * (students of one section do not all take the same subjects). Each has a
 * school ID number, an emergency contact and an address; some have a student
 * email or mobile number, a blood type or an allergy on record.
 *
 * Enrollment history: everyone was enrolled when the school year started, and
 * two Grade 1 children moved from Section 2 to Section 1 early in the year (so
 * their history has two stays).
 */
class DemoStudentsSeeder extends Seeder
{
    use DemoSchool;

    /** Students per section, by "Grade level / Section" (Main Campus unless noted). */
    private const ROSTERS = [
        'Kindergarten / Sunflower' => 8,
        'Grade 1 / Section 1' => 12,
        'Grade 1 / Section 2' => 10,
        'Grade 2 / Section A' => 12,
        'Grade 2 / Section B' => 10,
        'Grade 3 / Section A' => 10,
        'Grade 1 / Rose' => 8,
    ];

    /** Typical age by grade level. */
    private const AGES = ['Kindergarten' => 5, 'Grade 1' => 6, 'Grade 2' => 7, 'Grade 3' => 8];

    /** Allergies and conditions a few demo students have on record. */
    private const HEALTH_NOTES = [
        'Peanut allergy. Keeps an epinephrine pen in the clinic.',
        'Asthma; uses an inhaler before PE.',
        'Allergic to shrimp and crab.',
        'Lactose intolerant.',
        'Wears eyeglasses; sits near the board.',
    ];

    public function run(): void
    {
        $school = $this->requireSchool();
        $admin = $this->admin();
        $year = $this->currentYear();
        $students = app(StudentService::class);

        if (Student::query()->where('school_year_id', $year->id)->exists()) {
            $this->command?->info('The demo students already exist; skipping.');

            return;
        }

        $sections = Section::query()
            ->forOrganization($school->id)
            ->whereHas('gradeLevel', fn ($level) => $level->where('school_year_id', $year->id))
            ->with(['gradeLevel', 'subjects'])
            ->orderBy('id')
            ->get();

        $created = 0;

        // A separate, seeded generator for the contact details, so the names, ages and
        // subjects above come out exactly as before.
        $details = FakerFactory::create();
        $details->seed(2027);

        foreach ($sections as $section) {
            $count = self::ROSTERS[$section->gradeLevel->name.' / '.$section->name] ?? 8;
            $age = self::AGES[$section->gradeLevel->name] ?? 6;
            $offered = $section->subjects->sortBy('sort_order')->values();

            for ($i = 0; $i < $count; $i++) {
                $sex = fake()->randomElement(['male', 'female']);
                $birth = Carbon::now()->subYears($age)->subDays(fake()->numberBetween(0, 330));

                // About one student in four skips an optional subject the section offers.
                $subjects = $offered;

                if (fake()->boolean(28)) {
                    $subjects = $offered->reject(fn ($subject) => in_array($subject->name, ['Filipino', 'Science'], true) && fake()->boolean(70))->values();
                }

                $first = fake()->firstName($sex);
                $middle = fake()->boolean(75) ? fake()->lastName() : null;
                $last = fake()->lastName();

                $students->create([
                    'first_name' => $first,
                    'middle_name' => $middle,
                    'last_name' => $last,
                    'extension' => fake()->boolean(6) ? fake()->randomElement(['Jr.', 'III']) : null,
                    'birth_date' => $birth->toDateString(),
                    'age' => $birth->age,
                    'sex' => $sex,
                ] + $this->details($details, $created, $first, $last), $section, $admin, $subjects->pluck('id')->all());

                $created++;
            }
        }

        $this->enrollmentHistory($year, $students, $admin);

        $this->command?->info("Demo students enrolled: {$created}.");
    }

    /**
     * Everyone was enrolled when the school year started; two Grade 1 students then moved from
     * Section 2 to Section 1 (their first stay ends, a second begins), dated early in the year.
     */
    private function enrollmentHistory($year, StudentService $students, $admin): void
    {
        StudentEnrollment::query()->update(['started_on' => $year->start_date->toDateString()]);

        $level = GradeLevel::query()->where('school_year_id', $year->id)->where('name', 'Grade 1')
            ->whereHas('branch', fn ($branch) => $branch->where('name', 'Main Campus'))->firstOrFail();
        $from = $level->sections()->where('name', 'Section 2')->firstOrFail();
        $to = $level->sections()->where('name', 'Section 1')->firstOrFail();
        $movedOn = $year->start_date->copy()->addWeeks(9)->toDateString();

        foreach (Student::query()->where('section_id', $from->id)->orderBy('id')->take(2)->get() as $student) {
            $students->update($student, ['section_id' => $to->id], null, $admin);

            $stays = StudentEnrollment::query()->where('student_id', $student->id)->orderBy('id')->get();
            $stays[0]->update(['ended_on' => $movedOn]);
            $stays[1]->update(['started_on' => $movedOn]);
        }
    }

    /**
     * The ID number, contact, emergency and health details of the n-th demo student.
     *
     * @return array<string, mixed>
     */
    private function details(Generator $faker, int $index, string $first, string $last): array
    {
        $parent = $faker->randomElement(['Mother', 'Mother', 'Father', 'Guardian', 'Grandmother']);
        $gender = in_array($parent, ['Mother', 'Grandmother'], true) ? 'female' : ($parent === 'Father' ? 'male' : null);

        return [
            'student_number' => sprintf('2026-%04d', $index + 1),
            // A few have their own email or mobile number (the parents' is the emergency contact).
            'email' => $faker->boolean(20) ? Str::slug($first.'.'.$last, '.').'@students.example.com' : null,
            'mobile' => $faker->boolean(15) ? '09'.$faker->numerify('## ### ####') : null,
            'address' => $faker->streetAddress().', '.$faker->randomElement(['Quezon City', 'Pasig City', 'Makati City', 'Marikina City']),
            'guardian_name' => $faker->name($gender),
            'guardian_relationship' => $parent,
            'guardian_phone' => '09'.$faker->numerify('## ### ####'),
            'guardian_email' => $faker->boolean(70) ? $faker->safeEmail() : null,
            'blood_type' => $faker->boolean(60) ? $faker->randomElement(['O+', 'O+', 'A+', 'B+', 'AB+', 'O-']) : null,
            'medical_notes' => $faker->boolean(15) ? $faker->randomElement(self::HEALTH_NOTES) : null,
        ];
    }
}
