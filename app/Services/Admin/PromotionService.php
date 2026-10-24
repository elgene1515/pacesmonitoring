<?php

namespace App\Services\Admin;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Year-end promotion: move a section's students to the sections of the next
 * school year (or anywhere else in the school) in one transaction. Each
 * student gets their own destination; null keeps them where they are. The
 * moves go through the ordinary student update, so the subjects follow the
 * new section, every PACE record stays as history and the enrollment
 * history gets a row.
 */
class PromotionService
{
    public function __construct(private readonly StudentService $students)
    {
    }

    /**
     * @param  array<int, int|null>  $moves  student id => destination section id (null = stays)
     * @return array{moved: int, stayed: int, students: \Illuminate\Support\Collection<int, Student>}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function promote(Section $from, array $moves, User $by): array
    {
        $from->loadMissing('gradeLevel.branch');
        $organizationId = (int) $from->gradeLevel->branch->organization_id;

        $roster = $from->students()->whereKey(array_keys($moves))->get()->keyBy('id');

        foreach (array_keys($moves) as $studentId) {
            if (! $roster->has($studentId)) {
                throw ValidationException::withMessages([
                    'moves' => ['Every student must be enrolled in this section.'],
                ]);
            }
        }

        $targetIds = array_values(array_unique(array_filter($moves, fn ($id) => $id !== null)));
        $targets = Section::query()
            ->with('gradeLevel.branch')
            ->whereKey($targetIds)
            ->get()
            ->keyBy('id');

        foreach ($targetIds as $sectionId) {
            $target = $targets->get($sectionId);

            if ($target === null || (int) $target->gradeLevel->branch->organization_id !== $organizationId) {
                throw ValidationException::withMessages([
                    'moves' => ['A destination section is not one of your school\'s sections.'],
                ]);
            }

            if ((int) $target->id === (int) $from->id) {
                throw ValidationException::withMessages([
                    'moves' => ['A destination section is the section being promoted; pick "stays" instead.'],
                ]);
            }
        }

        return DB::transaction(function () use ($moves, $roster, $by): array {
            $moved = collect();
            $stayed = 0;

            foreach ($moves as $studentId => $sectionId) {
                if ($sectionId === null) {
                    $stayed++;

                    continue;
                }

                $moved->push($this->students->update($roster->get($studentId), ['section_id' => $sectionId], null, $by));
            }

            return ['moved' => $moved->count(), 'stayed' => $stayed, 'students' => $moved];
        });
    }
}
