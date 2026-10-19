<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\Student;
use App\Models\User;

class StudentTest extends ApiTestCase
{
    /**
     * A complete, valid student payload (grade level owned by the current user).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'extension' => 'Jr.',
            'birth_date' => '2015-03-14',
            'age' => 11,
            'sex' => 'male',
            'grade_level_id' => $this->gradeLevel('Grade 5')->id,
        ], $overrides);
    }

    public function test_a_user_can_add_a_student(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/students', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'Juan')
            ->assertJsonPath('data.middle_name', 'Santos')
            ->assertJsonPath('data.last_name', 'Dela Cruz')
            ->assertJsonPath('data.extension', 'Jr.')
            ->assertJsonPath('data.full_name', 'Dela Cruz, Juan Santos Jr.')
            ->assertJsonPath('data.birth_date', '2015-03-14')
            ->assertJsonPath('data.age', 11)
            ->assertJsonPath('data.sex', 'male')
            ->assertJsonPath('data.grade_level.name', 'Grade 5')
            ->assertJsonPath('data.assignments_count', 0);

        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'sex' => 'male',
            'created_by' => $user->id,
        ]);
    }

    public function test_optional_name_parts_may_be_omitted(): void
    {
        $this->signIn();

        $this->postJson('/api/students', $this->payload([
            'middle_name' => null,
            'extension' => null,
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'sex' => 'female',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.extension', null)
            ->assertJsonPath('data.full_name', 'Reyes, Maria');
    }

    public function test_student_creation_is_validated(): void
    {
        $this->signIn();

        $this->postJson('/api/students', [
            'first_name' => '',
            'last_name' => '',
            'birth_date' => '14/03/2015',
            'age' => 200,
            'sex' => 'other',
            'grade_level_id' => 999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'birth_date',
                'age',
                'sex',
                'grade_level_id',
            ]);

        $this->postJson('/api/students', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'birth_date', 'age', 'sex', 'grade_level_id']);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_a_student_cannot_be_enrolled_in_another_users_grade_level(): void
    {
        $this->signIn();
        $foreignGrade = $this->gradeLevel('Grade 5', User::factory()->create());

        $this->postJson('/api/students', $this->payload(['grade_level_id' => $foreignGrade->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id']);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_students_are_listed_ordered_by_name_with_grade_level_and_counts(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 3');

        $zed = $this->student(['first_name' => 'Zed', 'last_name' => 'Alvarez', 'grade_level_id' => $grade->id]);
        $amy = $this->student(['first_name' => 'Amy', 'last_name' => 'Alvarez', 'grade_level_id' => $grade->id]);
        $bob = $this->student(['first_name' => 'Bob', 'last_name' => 'Zulueta', 'grade_level_id' => $grade->id]);

        // Zed is enrolled in SY 2026-2027 (the grade level's school year).
        PaceAssignment::factory()->create(['student_id' => $zed->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->tested()->create(['student_id' => $zed->id, 'school_year' => '2026-2027', 'score' => 90]);
        // A record from an earlier school year is left out of the list's counts and average.
        PaceAssignment::factory()->tested()->create(['student_id' => $zed->id, 'school_year' => '2025-2026', 'score' => 100]);

        $response = $this->getJson('/api/students')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame(
            [$amy->id, $zed->id, $bob->id],
            array_column($response->json('data'), 'id')
        );

        // The average spans every record of the enrolled year: (90 + 0 for the untested PACE) / 2.
        $response->assertJsonPath('data.1.assignments_count', 2)
            ->assertJsonPath('data.1.tested_count', 1)
            ->assertJsonPath('data.1.issued_count', 0)
            ->assertJsonPath('data.1.assigned_count', 1)
            ->assertJsonPath('data.1.average_score', 45)
            ->assertJsonPath('data.0.assignments_count', 0)
            ->assertJsonPath('data.0.average_score', null)
            ->assertJsonPath('data.0.grade_level.id', $grade->id)
            ->assertJsonPath('data.0.grade_level.name', 'Grade 3');
    }

    public function test_students_can_be_searched_and_filtered_by_grade_level(): void
    {
        $this->signIn();
        $grade2 = $this->gradeLevel('Grade 2');
        $grade4 = $this->gradeLevel('Grade 4');

        $match = $this->student([
            'first_name' => 'Carlos',
            'middle_name' => 'Manalo',
            'last_name' => 'Garcia',
            'grade_level_id' => $grade2->id,
        ]);
        $this->student([
            'first_name' => 'Pedro',
            'middle_name' => null,
            'last_name' => 'Manaloto',
            'grade_level_id' => $grade4->id,
        ]);
        $this->student([
            'first_name' => 'Anna',
            'middle_name' => null,
            'last_name' => 'Lopez',
            'grade_level_id' => $grade2->id,
        ]);

        $this->getJson('/api/students?search=manalo')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/students?search=manalo&grade_level_id='.$grade2->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson('/api/students?grade_level_id='.$grade2->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/students?search=nobody')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_user_only_sees_their_own_students(): void
    {
        $this->signIn();
        $mine = $this->student(['first_name' => 'Mine', 'last_name' => 'Student']);

        $stranger = User::factory()->create();
        $theirs = $this->student(['first_name' => 'Mine', 'last_name' => 'Student'], $stranger);

        $this->getJson('/api/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson('/api/students?search=mine')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/students/'.$theirs->id)->assertNotFound();
        $this->putJson('/api/students/'.$theirs->id, $this->payload())->assertNotFound();
        $this->deleteJson('/api/students/'.$theirs->id)->assertNotFound();
        $this->getJson('/api/students/'.$theirs->id.'/assignments')->assertNotFound();

        $this->assertDatabaseHas('students', ['id' => $theirs->id, 'first_name' => 'Mine']);
    }

    public function test_a_student_detail_includes_grade_subjects_and_assignments(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1');
        $student = $this->student(['grade_level_id' => $grade->id]);

        $subject = $this->subject($grade, 'Math');
        PaceAssignment::factory()->count(2)->create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'school_year' => '2026-2027',
            'quarter' => 1,
        ]);

        $this->getJson('/api/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.grade_level.id', $grade->id)
            ->assertJsonCount(7, 'data.grade_level.subjects')
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.subject.name', 'Math')
            ->assertJsonStructure([
                'data' => [
                    'assignments' => [
                        ['id', 'student_id', 'subject_id', 'pace_id', 'school_year', 'quarter', 'issued_date', 'date_tested', 'score', 'remarks', 'pace' => ['id', 'name', 'number'], 'subject' => ['id', 'name']],
                    ],
                ],
            ]);
    }

    public function test_a_missing_student_returns_404_json(): void
    {
        $this->signIn();

        $this->getJson('/api/students/999')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_a_user_can_update_a_student(): void
    {
        $this->signIn();
        $student = $this->student(['grade_level_id' => $this->gradeLevel('Grade 1')->id]);
        $newGrade = $this->gradeLevel('Grade 2');

        $this->putJson('/api/students/'.$student->id, $this->payload([
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'sex' => 'female',
            'grade_level_id' => $newGrade->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.last_name', 'Name')
            ->assertJsonPath('data.sex', 'female')
            ->assertJsonPath('data.grade_level.id', $newGrade->id);

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'first_name' => 'Updated',
            'grade_level_id' => $newGrade->id,
        ]);
    }

    public function test_updating_a_student_is_validated(): void
    {
        $this->signIn();
        $student = $this->student();

        $this->putJson('/api/students/'.$student->id, $this->payload([
            'birth_date' => '2999-01-01',
            'age' => -1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birth_date', 'age']);
    }

    public function test_a_user_can_delete_a_student_and_their_assignments(): void
    {
        $this->signIn();
        $student = $this->student();
        PaceAssignment::factory()->create(['student_id' => $student->id]);

        $this->deleteJson('/api/students/'.$student->id)->assertNoContent();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseCount('pace_assignments', 0);
    }

    public function test_dashboard_summary_can_be_narrowed_to_a_grade_level_and_a_subject(): void
    {
        $this->signIn();
        $grade1 = $this->gradeLevel('Grade 1', null, ['Math', 'English']);
        $grade2 = $this->gradeLevel('Grade 2', null, ['Math']);
        $math1 = $this->subject($grade1, 'Math');
        $english1 = $this->subject($grade1, 'English');
        $math2 = $this->subject($grade2, 'Math');

        $pupil = $this->student(['grade_level_id' => $grade1->id]);
        $other = $this->student(['grade_level_id' => $grade2->id]);

        PaceAssignment::factory()->tested()->create(['student_id' => $pupil->id, 'subject_id' => $math1->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->create(['student_id' => $pupil->id, 'subject_id' => $english1->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->create(['student_id' => $other->id, 'subject_id' => $math2->id, 'school_year' => '2026-2027']);

        // One grade level: its students, its grade level row, and the records under its subjects.
        $this->getJson('/api/dashboard/summary?grade_level_id='.$grade1->id)
            ->assertOk()
            ->assertJsonPath('data.grade_levels', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonCount(2, 'data.by_subject')
            ->assertJsonCount(1, 'data.by_grade_level')
            ->assertJsonPath('data.by_grade_level.0.id', $grade1->id);

        // One subject: only its records; students and grade levels are untouched.
        $this->getJson('/api/dashboard/summary?subject_id='.$english1->id)
            ->assertOk()
            ->assertJsonPath('data.grade_levels', 2)
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.tested', 0)
            ->assertJsonCount(1, 'data.by_subject')
            ->assertJsonPath('data.by_subject.0.id', $english1->id);

        // Both together, including the "all school years" mode.
        $this->getJson('/api/dashboard/summary?school_year_id=all&grade_level_id='.$grade1->id.'&subject_id='.$math1->id)
            ->assertOk()
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.tested', 1);

        // Somebody else's grade level or subject is rejected.
        $stranger = User::factory()->create();
        $theirs = $this->gradeLevel('Grade 1', $stranger, ['Math']);

        $this->getJson('/api/dashboard/summary?grade_level_id='.$theirs->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id']);

        $this->getJson('/api/dashboard/summary?subject_id='.$this->subject($theirs, 'Math')->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_dashboard_summary_can_be_narrowed_to_one_pace(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1', null, ['Math']);
        $math = $this->subject($grade, 'Math');
        $pupil = $this->student(['grade_level_id' => $grade->id]);
        $paceA = $this->pace(['number' => '1001']);
        $paceB = $this->pace(['number' => '1002']);

        PaceAssignment::factory()->tested()->create(['student_id' => $pupil->id, 'subject_id' => $math->id, 'pace_id' => $paceA->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->create(['student_id' => $pupil->id, 'subject_id' => $math->id, 'pace_id' => $paceB->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->create(['student_id' => $pupil->id, 'subject_id' => $math->id, 'pace_id' => $paceB->id, 'school_year' => '2026-2027']);

        $this->getJson('/api/dashboard/summary?school_year_id=all')
            ->assertOk()
            ->assertJsonPath('data.paces', 2)
            ->assertJsonPath('data.assignments', 3);

        // One PACE: its records only, and just that PACE in the PACE count; students are untouched.
        $this->getJson('/api/dashboard/summary?school_year_id=all&pace_id='.$paceA->id)
            ->assertOk()
            ->assertJsonPath('data.paces', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonPath('data.totals.paces', 2);

        $this->getJson('/api/dashboard/summary?school_year_id=all&subject_id='.$math->id.'&pace_id='.$paceB->id)
            ->assertOk()
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonPath('data.tested', 0);

        // Somebody else's PACE is rejected.
        $stranger = User::factory()->create();

        $this->getJson('/api/dashboard/summary?pace_id='.$this->pace([], $stranger)->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_id']);
    }

    public function test_dashboard_summary_reports_the_users_own_counts_and_recent_students(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 6');

        $students = Student::factory()->count(6)->create([
            'grade_level_id' => $grade->id,
            'school_year_id' => $grade->school_year_id,
            'created_by' => $this->user->id,
        ]);

        foreach (range(1, 3) as $ignored) {
            $this->assignment(['student_id' => $students->first()->id]);
        }

        PaceAssignment::factory()->tested()->create([
            'student_id' => $students->last()->id,
            'pace_id' => $this->pace()->id,
            'score' => 70,
            'retake' => true,
        ]);

        // Another user's records must not leak into the summary.
        $stranger = User::factory()->create();
        $this->student([], $stranger);
        $this->pace([], $stranger);
        $this->assignment([], $stranger);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.grade_levels', 1)
            ->assertJsonPath('data.students', 6)
            ->assertJsonPath('data.assignments', 4)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonPath('data.issued', 0)
            ->assertJsonPath('data.assigned', 3)
            ->assertJsonPath('data.scored', 1)
            ->assertJsonPath('data.passed', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.retakes', 1)
            ->assertJsonCount(1, 'data.failed')
            ->assertJsonStructure(['data' => [
                'by_quarter' => ['*' => ['quarter', 'total', 'tested', 'issued', 'assigned', 'passed', 'failed', 'retakes']],
                'by_grade_level' => ['*' => ['id', 'name', 'total', 'male', 'female']],
            ]])
            ->assertJsonPath('data.by_grade_level.0.total', 6)
            ->assertJsonPath('data.failed.0.failed', true)
            ->assertJsonPath('data.failed.0.retake', true)
            ->assertJsonPath('data.failed.0.score', 70)
            ->assertJsonPath('data.paces', 4)
            ->assertJsonCount(5, 'data.recent_students')
            ->assertJsonPath('data.recent_students.0.id', $students->last()->id)
            ->assertJsonPath('data.recent_students.0.grade_level.name', 'Grade 6');
    }

    public function test_guests_cannot_manage_students(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
        $this->postJson('/api/students', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('students', 0);
    }
}
