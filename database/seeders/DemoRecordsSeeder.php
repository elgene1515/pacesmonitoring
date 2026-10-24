<?php

namespace Database\Seeders;

use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\Student;
use App\Models\TeachingAssignment;
use App\Services\PaceAssignmentService;
use App\Services\StudentChangeRequestService;
use Database\Seeders\Concerns\DemoSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * First-quarter PACE records for the students, in every state (assigned,
 * issued, tested with a score, retakes) under the subjects each student takes
 * and the teacher teaches, plus some second-quarter assignments, and student
 * change requests (pending, applied and declined; one of the pending ones is
 * a contact / health detail change).
 */
class DemoRecordsSeeder extends Seeder
{
    use DemoSchool;

    public function run(): void
    {
        $school = $this->requireSchool();
        $admin = $this->admin();
        $records = app(PaceAssignmentService::class);

        if (PaceAssignment::query()->exists()) {
            $this->command?->info('PACE records already exist; skipping.');

            return;
        }

        $assignments = TeachingAssignment::query()
            ->whereHas('section', fn ($section) => $section->forOrganization($school->id))
            ->with(['teacher', 'subject'])
            ->get();

        $created = 0;

        foreach ($assignments as $assignment) {
            $students = Student::query()
                ->where('section_id', $assignment->section_id)
                ->whereHas('subjects', fn ($subjects) => $subjects->whereKey($assignment->subject_id))
                ->orderBy('id')
                ->get();

            $paces = $this->paces($assignment->teacher->branch_id, $assignment->subject->name, DemoPacesSeeder::QUARTER_ONE);

            foreach ($students as $student) {
                $issued = fake()->boolean(78);
                // Issued a few weeks ago, so tested dates (a week or two later) are never in the future.
                $issuedDate = Carbon::today()->subDays(fake()->numberBetween(30, 55))->toDateString();

                $rows = $records->assignMany($student, [
                    'subject_id' => $assignment->subject_id,
                    'pace_ids' => $paces->pluck('id')->all(),
                    'school_year' => self::DEMO_YEAR,
                    'quarter' => 1,
                    'issued_date' => $issued ? $issuedDate : null,
                ], $assignment->teacher);

                $created += $rows->count();

                if (! $issued) {
                    continue;
                }

                // The first PACEs are usually tested, the last often not yet.
                foreach ($rows->sortBy(fn ($row) => $row->pace->number)->values() as $index => $row) {
                    if (! fake()->boolean([92, 62, 24][$index] ?? 20)) {
                        continue;
                    }

                    $score = fake()->numberBetween(52, 100);

                    PaceAssignment::query()->whereKey($row->id)->update([
                        'date_tested' => Carbon::parse($issuedDate)->addDays(fake()->numberBetween(5, 18))->min(Carbon::today())->toDateString(),
                        'score' => $score,
                        'retake' => $score < 80 && fake()->boolean(35),
                    ]);
                }
            }

            // Some second-quarter PACEs are already assigned (not issued yet).
            $next = $this->paces($assignment->teacher->branch_id, $assignment->subject->name, ['1004', '1005']);

            foreach ($students->take((int) ceil($students->count() / 3)) as $student) {
                $records->assignMany($student, [
                    'subject_id' => $assignment->subject_id,
                    'pace_ids' => $next->pluck('id')->all(),
                    'school_year' => self::DEMO_YEAR,
                    'quarter' => 2,
                    'issued_date' => null,
                ], $assignment->teacher);
            }
        }

        $this->changeRequests($school->id, $admin);

        $this->command?->info("Demo PACE records created ({$created} in the first quarter).");
    }

    /** @param  array<int, string>  $numbers */
    private function paces(int $branchId, string $name, array $numbers)
    {
        return Pace::query()->where('branch_id', $branchId)->where('name', $name)->whereIn('number', $numbers)->orderBy('number')->get();
    }

    /**
     * Teachers' proposed corrections: one applied, one declined, one waiting.
     */
    private function changeRequests(int $schoolId, $admin): void
    {
        $changes = app(StudentChangeRequestService::class);

        $visibleTo = fn (string $firstName) => Student::query()->ownedBy($this->teacher($firstName))->orderBy('id');

        // Each proposal differs from the student's current details (the service refuses a no-op).
        $first = $visibleTo('Ben')->firstOrFail();
        $applied = $changes->propose($first, $this->teacher('Ben'), ['first_name' => $first->first_name === 'Jonathan' ? 'Johnny' : 'Jonathan']);
        $changes->accept($applied, $admin);

        $second = $visibleTo('Carla')->firstOrFail();
        $declined = $changes->propose($second, $this->teacher('Carla'), ['sex' => $second->sex->value === 'male' ? 'female' : 'male']);
        $changes->decline($declined, $admin, 'The record matches the birth certificate.');

        // Ana's is left pending, on a student of hers who is not Ben's or Carla's.
        $student = $visibleTo('Ana')->whereNotIn('id', [$applied->student_id, $declined->student_id])->firstOrFail();
        $changes->propose($student, $this->teacher('Ana'), ['last_name' => $student->last_name === 'Dela Cruz' ? 'De la Cruz' : 'Dela Cruz']);

        // Diego proposes a new emergency phone and a health remark (the contact, emergency and health details can be proposed too).
        $other = $visibleTo('Diego')->whereNotIn('id', [$applied->student_id, $declined->student_id, $student->id])->firstOrFail();
        $changes->propose($other, $this->teacher('Diego'), [
            'guardian_phone' => '0917 999 8888',
            'medical_notes' => 'Wears eyeglasses; please seat near the board.',
        ]);
    }
}
