<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\TeachingAssignment;
use App\Models\User;

/**
 * Year-end promotion and the enrollment history: a section's students move
 * to next year's sections in one go, each PACE record stays where it was
 * made, and every stay in a section is kept on the student.
 */
class PromotionTest extends ApiTestCase
{
    public function test_enrolling_and_moving_a_student_writes_the_enrollment_history(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $this->signIn($admin);

        $grade1 = $this->gradeLevel('Grade 1', $admin, ['Math']);
        $sectionOne = $this->section($grade1, $admin);
        $sectionTwo = Section::factory()->create(['grade_level_id' => $grade1->id, 'name' => 'Section 2']);

        $id = $this->postJson('/api/admin/students', [
            'first_name' => 'Pia',
            'last_name' => 'Cruz',
            'birth_date' => '2019-05-05',
            'age' => 7,
            'sex' => 'female',
            'section_id' => $sectionOne->id,
        ])->assertCreated()->json('data.id');

        // Enrolling opens the first stay.
        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $id,
            'section_id' => $sectionOne->id,
            'section_name' => $sectionOne->name,
            'grade_level_name' => 'Grade 1',
            'ended_on' => null,
            'moved_by' => $admin->id,
        ]);

        $details = ['first_name' => 'Pia', 'last_name' => 'Cruz', 'birth_date' => '2019-05-05', 'age' => 7, 'sex' => 'female'];

        // Moving closes it and opens the next one; the page shows both, latest first.
        $this->putJson('/api/admin/students/'.$id, $details + ['section_id' => $sectionTwo->id])->assertOk();

        $stays = StudentEnrollment::query()->where('student_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $stays);
        $this->assertNotNull($stays[0]->ended_on);
        $this->assertSame($sectionTwo->id, $stays[1]->section_id);
        $this->assertNull($stays[1]->ended_on);

        $this->getJson('/api/admin/students/'.$id)
            ->assertOk()
            ->assertJsonCount(2, 'data.enrollments')
            ->assertJsonPath('data.enrollments.0.section_name', 'Section 2')
            ->assertJsonPath('data.enrollments.0.is_current', true)
            ->assertJsonPath('data.enrollments.1.section_name', $sectionOne->name)
            ->assertJsonPath('data.enrollments.1.is_current', false);

        // Editing details without a move adds nothing.
        $this->putJson('/api/admin/students/'.$id, ['first_name' => 'Pia Maria'] + $details + ['section_id' => $sectionTwo->id])->assertOk();
        $this->assertSame(2, StudentEnrollment::query()->where('student_id', $id)->count());
    }

    public function test_a_section_is_promoted_to_next_years_sections_with_per_student_destinations(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $nextTeacher = $this->teacher($organization);

        $thisYear = $this->schoolYear('2025-2026', $admin, ['is_current' => true]);
        $nextYear = $this->schoolYear('2026-2027', $admin);

        $grade1 = $this->gradeLevel('Grade 1', $teacher, ['Math'], $thisYear);
        $from = $this->section($grade1, $teacher);
        $math1 = $this->subject($grade1, 'Math');

        $grade1Next = $this->gradeLevel('Grade 1', $admin, ['Math'], $nextYear);
        $grade2Next = $this->gradeLevel('Grade 2', $admin, ['Math', 'Science'], $nextYear);
        $repeatTarget = $this->section($grade1Next, $admin);
        $target = $this->section($grade2Next, $admin);
        TeachingAssignment::query()->create(['section_id' => $target->id, 'subject_id' => $this->subject($grade2Next, 'Math')->id, 'teacher_id' => $nextTeacher->id, 'assigned_by' => $admin->id]);

        $ana = $this->student(['section_id' => $from->id, 'first_name' => 'Ana'], $teacher);
        $ben = $this->student(['section_id' => $from->id, 'first_name' => 'Ben'], $teacher);
        $cara = $this->student(['section_id' => $from->id, 'first_name' => 'Cara'], $teacher);
        $record = PaceAssignment::factory()->tested()->create(['student_id' => $ana->id, 'subject_id' => $math1->id, 'school_year' => '2025-2026', 'score' => 90]);

        $this->signIn($admin);

        // Ana and Ben go up, Cara repeats Grade 1; nobody is left behind here.
        $this->postJson('/api/admin/sections/'.$from->id.'/promote', [
            'moves' => [
                ['student_id' => $ana->id, 'section_id' => $target->id],
                ['student_id' => $ben->id, 'section_id' => $target->id],
                ['student_id' => $cara->id, 'section_id' => $repeatTarget->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.moved', 3)
            ->assertJsonPath('data.stayed', 0)
            ->assertJsonCount(3, 'data.students');

        $this->assertDatabaseHas('students', ['id' => $ana->id, 'section_id' => $target->id, 'school_year_id' => $nextYear->id]);
        $this->assertDatabaseHas('students', ['id' => $cara->id, 'section_id' => $repeatTarget->id, 'school_year_id' => $nextYear->id]);

        // Subjects follow the new section; the old record is untouched.
        $this->assertEqualsCanonicalizing(
            $grade2Next->subjects()->pluck('id')->all(),
            $ana->refresh()->subjects()->pluck('subjects.id')->all()
        );
        $this->assertDatabaseHas('pace_assignments', ['id' => $record->id, 'student_id' => $ana->id, 'subject_id' => $math1->id, 'score' => 90]);

        // Two stays each: Grade 1 (closed) then the new one (open).
        $this->assertSame(2, StudentEnrollment::query()->where('student_id', $ana->id)->count());
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $ana->id, 'section_id' => $target->id, 'grade_level_name' => 'Grade 2', 'school_year_name' => '2026-2027', 'ended_on' => null, 'moved_by' => $admin->id]);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $cara->id, 'section_id' => $repeatTarget->id, 'grade_level_name' => 'Grade 1', 'school_year_name' => '2026-2027', 'ended_on' => null]);

        // The old teacher no longer sees them; the new one does, with last year's record as history.
        $this->forgetGuards();
        $this->signIn($teacher);
        $this->getJson('/api/students/'.$ana->id)->assertNotFound();

        $this->forgetGuards();
        $this->signIn($nextTeacher);
        $this->getJson('/api/students/'.$ana->id)
            ->assertOk()
            ->assertJsonPath('data.section_id', $target->id)
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.school_year', '2025-2026')
            ->assertJsonCount(2, 'data.enrollments');

        // The old section is empty now, so it can be deleted; the history keeps its name.
        $this->forgetGuards();
        $this->signIn($admin);
        $this->deleteJson('/api/admin/sections/'.$from->id)->assertNoContent();
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $ana->id, 'section_id' => null, 'section_name' => $from->name]);
    }

    public function test_promotion_can_leave_students_behind_and_refuses_bad_moves(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $this->signIn($admin);

        $grade1 = $this->gradeLevel('Grade 1', $admin, ['Math']);
        $from = $this->section($grade1, $admin);
        $grade2 = $this->gradeLevel('Grade 2', $admin, ['Math']);
        $target = $this->section($grade2, $admin);

        $stays = $this->student(['section_id' => $from->id], $admin);
        $goes = $this->student(['section_id' => $from->id], $admin);
        $elsewhere = $this->student(['section_id' => $target->id], $admin);

        // null = stays.
        $this->postJson('/api/admin/sections/'.$from->id.'/promote', [
            'moves' => [
                ['student_id' => $stays->id, 'section_id' => null],
                ['student_id' => $goes->id, 'section_id' => $target->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.stayed', 1);

        $this->assertDatabaseHas('students', ['id' => $stays->id, 'section_id' => $from->id]);
        $this->assertSame(1, StudentEnrollment::query()->where('student_id', $stays->id)->count());

        // A student of another section, the same section as destination, and another school's section.
        $this->postJson('/api/admin/sections/'.$from->id.'/promote', ['moves' => [['student_id' => $elsewhere->id, 'section_id' => $target->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['moves']);

        $this->postJson('/api/admin/sections/'.$from->id.'/promote', ['moves' => [['student_id' => $stays->id, 'section_id' => $from->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['moves']);

        $foreign = $this->section($this->gradeLevel('Grade 2', User::factory()->create(), ['Math']));
        $this->postJson('/api/admin/sections/'.$from->id.'/promote', ['moves' => [['student_id' => $stays->id, 'section_id' => $foreign->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['moves']);

        $this->postJson('/api/admin/sections/'.$from->id.'/promote', ['moves' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['moves']);

        // Teachers cannot promote.
        $this->forgetGuards();
        $this->signIn($this->teacher($organization));
        $this->postJson('/api/admin/sections/'.$from->id.'/promote', ['moves' => [['student_id' => $stays->id, 'section_id' => $target->id]]])->assertForbidden();
    }
}
