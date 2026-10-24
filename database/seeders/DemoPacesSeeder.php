<?php

namespace Database\Seeders;

use App\Models\Pace;
use App\Models\PaceRequest;
use App\Models\TeachingAssignment;
use App\Services\PaceRequestService;
use App\Services\PaceService;
use App\Services\PaceStockService;
use Database\Seeders\Concerns\DemoSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Each branch's PACE catalog with booklets received into the pool, a few
 * pools running low or empty, the booklets handed to the teachers who will
 * issue them (enough for the demo records), and stock requests in every
 * state: pending, given and declined.
 */
class DemoPacesSeeder extends Seeder
{
    use DemoSchool;

    /** Subject name => [first number, last number, booklets received per PACE]. */
    private const MAIN_CATALOG = [
        'Math' => [1001, 1012, 120],
        'English' => [1001, 1012, 120],
        'Filipino' => [1001, 1008, 80],
        'Science' => [1001, 1008, 80],
        'Reading and Writing' => [1001, 1004, 30],
    ];

    private const NORTH_CATALOG = [
        'Math' => [1001, 1008, 40],
        'English' => [1001, 1008, 40],
    ];

    /** The PACEs the demo records use in the first quarter. */
    public const QUARTER_ONE = ['1001', '1002', '1003'];

    public function run(): void
    {
        $school = $this->requireSchool();
        $admin = $this->admin();
        $paces = app(PaceService::class);
        $stock = app(PaceStockService::class);

        if (Pace::query()->whereIn('branch_id', $school->branches()->pluck('id'))->exists()) {
            $this->command?->info('The demo PACEs already exist; skipping.');

            return;
        }

        foreach ([['Main Campus', self::MAIN_CATALOG], ['North Campus', self::NORTH_CATALOG]] as [$branchName, $catalog]) {
            $branch = $this->branch($branchName);

            foreach ($catalog as $name => [$start, $end, $received]) {
                $paces->createRange($branch, ['name' => $name, 'start' => (string) $start, 'end' => (string) $end, 'pool_stock' => $received], $admin);
            }
        }

        // A pool running low and an empty one, so the badges show.
        $main = $this->branch('Main Campus');
        $stock->adjustPool($this->pace($main->id, 'Math', '1012'), -117, $admin, 'Recount: most booklets went to the other campus.');
        $stock->adjustPool($this->pace($main->id, 'Filipino', '1008'), -80, $admin, 'Recount: none left on the shelf.');

        // Hand each teacher the booklets for their first-quarter records, plus a few spare.
        $needs = [];

        foreach (TeachingAssignment::query()->whereIn('section_id', $this->sectionIds($school->id))->with(['subject', 'teacher'])->get() as $assignment) {
            $takers = DB::table('student_subjects')
                ->join('students', 'students.id', '=', 'student_subjects.student_id')
                ->where('students.section_id', $assignment->section_id)
                ->where('student_subjects.subject_id', $assignment->subject_id)
                ->count();

            $needs[$assignment->teacher_id][$assignment->subject->name] = ($needs[$assignment->teacher_id][$assignment->subject->name] ?? 0) + $takers;
        }

        foreach ($needs as $teacherId => $subjects) {
            $teacher = \App\Models\User::query()->findOrFail($teacherId);

            foreach ($subjects as $subjectName => $takers) {
                foreach (self::QUARTER_ONE as $number) {
                    $pace = $this->pace($teacher->branch_id, $subjectName, $number);
                    $stock->distribute($pace, $teacher, $takers + 4, $admin, null, 'Start of the school year.');
                }

                // Spare booklets of the next PACEs.
                foreach (['1004', '1005'] as $number) {
                    $stock->distribute($this->pace($teacher->branch_id, $subjectName, $number), $teacher, 3, $admin);
                }
            }
        }

        $this->requests($school->id, $admin);

        $this->command?->info('Demo PACE catalogs, pools, teacher stock and stock requests created.');
    }

    private function requests(int $schoolId, $admin): void
    {
        $requests = app(PaceRequestService::class);
        $main = $this->branch('Main Campus');

        // Pending: a catalog PACE, and a PACE that is not in the catalog yet.
        $requests->submit($this->teacher('Ana'), [
            'pace_id' => $this->pace($main->id, 'Math', '1007')->id,
            'quantity' => 10,
            'needed_by' => Carbon::today()->addDays(14)->toDateString(),
            'message' => 'For the second quarter.',
        ]);

        $requests->submit($this->teacher('Ben'), [
            'pace_number' => '1009',
            'pace_name' => 'Science',
            'quantity' => 6,
            'message' => 'Not in the catalog yet.',
        ]);

        // Decided: one given, one declined.
        $given = $requests->submit($this->teacher('Carla'), [
            'pace_id' => $this->pace($main->id, 'Filipino', '1006')->id,
            'quantity' => 5,
        ]);
        $requests->give($given, $admin, ['quantity' => 5, 'decision_note' => 'Picked up at the office.']);

        $declined = $requests->submit($this->teacher('Diego'), [
            'pace_id' => $this->pace($main->id, 'English', '1010')->id,
            'quantity' => 40,
            'message' => 'For the whole grade.',
        ]);
        $requests->decline($declined, $admin, 'Too many at once; ask again in smaller batches.');
    }

    private function pace(int $branchId, string $name, string $number): Pace
    {
        return Pace::query()->where('branch_id', $branchId)->where('name', $name)->where('number', $number)->firstOrFail();
    }

    /** @return array<int, int> */
    private function sectionIds(int $schoolId): array
    {
        return \App\Models\Section::query()->forOrganization($schoolId)->pluck('id')->all();
    }
}
