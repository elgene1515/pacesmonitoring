<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use App\Services\GradeLevelService;
use App\Services\TeachingAssignmentService;
use Database\Seeders\Concerns\DemoSchool;
use Illuminate\Database\Seeder;

/**
 * Each branch's grade levels for the current school year with their subjects
 * and sections, the subjects each section offers, who teaches what (some
 * classes are left open on purpose) and a pending class request.
 *
 * Grade 1 is the "not every section offers every subject" example: Section 1
 * has English, Math and Filipino, Section 2 only English and Math.
 *
 * Next school year (registered by DemoSchoolSeeder) gets the Main Campus grade
 * levels, subjects and sections too, with no teachers and no students, so the
 * admin can try the year-end *Promote students* right away.
 */
class DemoGradeLevelsSeeder extends Seeder
{
    use DemoSchool;

    public function run(): void
    {
        $school = $this->requireSchool();
        $admin = $this->admin();
        $year = $this->currentYear();
        $levels = app(GradeLevelService::class);
        $teaching = app(TeachingAssignmentService::class);

        if (GradeLevel::query()->where('school_year_id', $year->id)->exists()) {
            $this->command?->info('The demo grade levels already exist; skipping.');

            return;
        }

        // Per branch and grade level: its subjects, its sections (with the subjects each does not
        // offer) and the teacher of each subject per section (a missing subject is an open class).
        $structure = [
            'Main Campus' => [
                'Kindergarten' => [
                    'subjects' => ['English', 'Math', 'Filipino'],
                    'sections' => ['Sunflower' => []],
                    'teachers' => [
                        'Sunflower' => ['English' => 'Ana', 'Math' => 'Ben', 'Filipino' => 'Ana'],
                    ],
                ],
                'Grade 1' => [
                    'subjects' => ['English', 'Math', 'Filipino'],
                    'sections' => ['Section 1' => [], 'Section 2' => ['Filipino']],
                    'teachers' => [
                        'Section 1' => ['English' => 'Ana', 'Math' => 'Ben', 'Filipino' => 'Carla'],
                        'Section 2' => ['English' => 'Diego', 'Math' => 'Ben'],
                    ],
                ],
                'Grade 2' => [
                    'subjects' => ['English', 'Math', 'Filipino', 'Science'],
                    'sections' => ['Section A' => [], 'Section B' => []],
                    'teachers' => [
                        'Section A' => ['English' => 'Diego', 'Math' => 'Carla', 'Filipino' => 'Carla', 'Science' => 'Ben'],
                        // Section B: Filipino and Science have no teacher yet.
                        'Section B' => ['English' => 'Diego', 'Math' => 'Carla'],
                    ],
                ],
                'Grade 3' => [
                    'subjects' => ['English', 'Math', 'Science', 'Reading and Writing'],
                    'sections' => ['Section A' => []],
                    // Reading and Writing is open (and requested below).
                    'teachers' => [
                        'Section A' => ['English' => 'Ana', 'Math' => 'Ben', 'Science' => 'Diego'],
                    ],
                ],
            ],
            'North Campus' => [
                'Grade 1' => [
                    'subjects' => ['English', 'Math'],
                    'sections' => ['Rose' => []],
                    'teachers' => [
                        'Rose' => ['English' => 'Elena', 'Math' => 'Felix'],
                    ],
                ],
            ],
        ];

        foreach ($structure as $branchName => $gradeLevels) {
            $branch = $this->branch($branchName);

            foreach ($gradeLevels as $levelName => $definition) {
                $level = $levels->create($branch, ['name' => $levelName, 'school_year_id' => $year->id], $admin);

                $subjects = [];

                foreach ($definition['subjects'] as $subjectName) {
                    $subjects[$subjectName] = $levels->createSubject($level, ['name' => $subjectName]);
                }

                foreach ($definition['sections'] as $sectionName => $notOffered) {
                    $section = $levels->createSection($level, ['name' => $sectionName]);

                    foreach ($notOffered as $subjectName) {
                        $levels->withdrawSubject($section, $subjects[$subjectName]);
                    }

                    foreach ($definition['teachers'][$sectionName] ?? [] as $subjectName => $teacherName) {
                        $teaching->assign($section, $subjects[$subjectName], $this->teacher($teacherName), $admin);
                    }
                }
            }
        }

        $this->createNextYearClasses($levels, $structure['Main Campus'], $admin);

        // Carla (Main Campus) asks for the open Reading and Writing class of Grade 3.
        $grade3 = GradeLevel::query()->where('school_year_id', $year->id)->where('name', 'Grade 3')->firstOrFail();
        $section = $grade3->sections()->firstOrFail();
        $subject = $grade3->subjects()->where('name', 'Reading and Writing')->firstOrFail();

        $teaching->request($this->teacher('Carla'), $section, $subject, 'I taught this subject last year.');

        $this->command?->info('Demo grade levels, sections, subjects and teaching assignments created.');
    }

    /**
     * Next school year's Main Campus classes: the same grade levels (minus Kindergarten, whose
     * children move up to Grade 1) with their sections and subjects, plus a Grade 4 for the
     * Grade 3 children. Every section offers every subject; nobody teaches or is enrolled yet.
     *
     * @param  array<string, array<string, mixed>>  $mainCampus  the current year's Main Campus structure
     */
    private function createNextYearClasses(GradeLevelService $levels, array $mainCampus, $admin): void
    {
        $year = $this->nextYear();
        $branch = $this->branch('Main Campus');

        $classes = [
            'Grade 1' => ['subjects' => $mainCampus['Grade 1']['subjects'], 'sections' => ['Section 1', 'Section 2']],
            'Grade 2' => ['subjects' => $mainCampus['Grade 2']['subjects'], 'sections' => ['Section A', 'Section B']],
            'Grade 3' => ['subjects' => $mainCampus['Grade 3']['subjects'], 'sections' => ['Section A']],
            'Grade 4' => ['subjects' => ['English', 'Math', 'Science', 'Reading and Writing'], 'sections' => ['Section A']],
        ];

        foreach ($classes as $levelName => $definition) {
            $level = $levels->create($branch, ['name' => $levelName, 'school_year_id' => $year->id], $admin);

            foreach ($definition['subjects'] as $subjectName) {
                $levels->createSubject($level, ['name' => $subjectName]);
            }

            foreach ($definition['sections'] as $sectionName) {
                $levels->createSection($level, ['name' => $sectionName]);
            }
        }
    }
}
