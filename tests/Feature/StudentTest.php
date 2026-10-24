<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Students, teacher side: the students of the sections a teacher teaches.
 * Teachers enroll them and propose changes to their details; the admin
 * applies the changes (and edits students directly, see AdminTest).
 */
class StudentTest extends ApiTestCase
{
    /**
     * A complete, valid student payload (a section the current user teaches).
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
            'section_id' => $this->section($this->gradeLevel('Grade 5'))->id,
        ], $overrides);
    }

    public function test_a_teacher_can_enroll_a_student_in_a_section_they_teach(): void
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
            ->assertJsonPath('data.section.name', 'Section 1')
            ->assertJsonPath('data.grade_level.name', 'Grade 5')
            ->assertJsonPath('data.school_year.name', '2026-2027')
            ->assertJsonPath('data.assignments_count', 0);

        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'sex' => 'male',
            'section_id' => $response->json('data.section_id'),
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
            'section_id' => 999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'birth_date',
                'age',
                'sex',
                'section_id',
            ]);

        $this->postJson('/api/students', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'birth_date', 'age', 'sex', 'section_id']);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_a_student_can_only_be_enrolled_in_a_section_the_teacher_teaches(): void
    {
        $teacher = $this->signIn();
        $grade = $this->gradeLevel('Grade 5');

        // A section of the same grade level nobody teaches in ...
        $open = $this->emptySection($grade);

        $this->postJson('/api/students', $this->payload(['section_id' => $open->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

        // ... a colleague's section ...
        $colleague = $this->teacher($teacher->organization);
        $theirs = $this->section($grade, $colleague);

        $this->postJson('/api/students', $this->payload(['section_id' => $theirs->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

        // ... or another school's.
        $stranger = User::factory()->create();
        $foreign = $this->section($this->gradeLevel('Grade 5', $stranger), $stranger);

        $this->postJson('/api/students', $this->payload(['section_id' => $foreign->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_students_are_listed_ordered_by_name_with_section_grade_level_and_counts(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 3');
        $section = $this->section($grade);

        $zed = $this->student(['first_name' => 'Zed', 'last_name' => 'Alvarez', 'section_id' => $section->id]);
        $amy = $this->student(['first_name' => 'Amy', 'last_name' => 'Alvarez', 'section_id' => $section->id]);
        $bob = $this->student(['first_name' => 'Bob', 'last_name' => 'Zulueta', 'section_id' => $section->id]);

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
            ->assertJsonPath('data.0.section.id', $section->id)
            ->assertJsonPath('data.0.grade_level.id', $grade->id)
            ->assertJsonPath('data.0.grade_level.name', 'Grade 3');
    }

    public function test_students_can_be_searched_and_filtered_by_grade_level_and_section(): void
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

        $this->getJson('/api/students?section_id='.$this->section($grade4)->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/students?search=nobody')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_teacher_only_sees_students_of_the_sections_they_teach(): void
    {
        $teacher = $this->signIn();
        $mine = $this->student(['first_name' => 'Mine', 'last_name' => 'Student']);

        // A colleague's own section in the same branch, and another school altogether.
        $colleague = $this->teacher($teacher->organization);
        $theirs = $this->student(['first_name' => 'Mine', 'last_name' => 'Student'], $colleague);
        $this->student(['first_name' => 'Mine', 'last_name' => 'Student'], User::factory()->create());

        $this->getJson('/api/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson('/api/students?search=mine')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/students/'.$theirs->id)->assertNotFound();
        $this->postJson('/api/students/'.$theirs->id.'/change-requests', ['first_name' => 'Hijacked'])->assertNotFound();
        $this->getJson('/api/students/'.$theirs->id.'/assignments')->assertNotFound();

        $this->assertDatabaseHas('students', ['id' => $theirs->id, 'first_name' => 'Mine']);
    }

    public function test_a_student_is_shared_by_every_teacher_of_their_section(): void
    {
        $organization = $this->organization();
        $ann = $this->teacher($organization);
        $bob = $this->teacher($organization);
        $grade = $this->gradeLevel('Grade 1', $ann, ['Math', 'English']);
        $section = $this->section($grade, $ann);
        // Ann keeps Math, Bob takes English.
        $this->assign($bob, $section, $this->subject($grade, 'English'));

        $pupil = $this->student(['section_id' => $section->id], $ann);

        $this->signIn($ann);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pupil->id);

        $this->forgetGuards();
        $this->signIn($bob);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pupil->id);

        $this->getJson('/api/students/'.$pupil->id)
            ->assertOk()
            ->assertJsonPath('data.registered_by.id', $ann->id)
            ->assertJsonCount(2, 'data.section.teaching_assignments');
    }

    public function test_a_student_detail_includes_section_subjects_teachers_and_assignments(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1');
        $section = $this->section($grade);
        $student = $this->student(['section_id' => $section->id]);

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
            ->assertJsonPath('data.section.id', $section->id)
            ->assertJsonPath('data.grade_level.id', $grade->id)
            ->assertJsonCount(7, 'data.section.grade_level.subjects')
            ->assertJsonCount(7, 'data.section.teaching_assignments')
            ->assertJsonPath('data.section.teaching_assignments.0.teacher.id', $this->user->id)
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

    public function test_a_teacher_proposes_changes_to_a_student_and_the_admin_applies_them(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization, ['name' => 'Tim Teacher']);
        $this->signIn($teacher);
        $student = $this->student(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Cruz', 'extension' => null, 'birth_date' => '2015-03-14', 'age' => 11, 'sex' => 'male']);

        // Only what differs is kept.
        $response = $this->postJson('/api/students/'.$student->id.'/change-requests', [
            'first_name' => 'Juan Miguel',
            'last_name' => 'Cruz',
            'birth_date' => '2014-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.requested_by', $teacher->id)
            ->assertJsonPath('data.changes', ['first_name' => 'Juan Miguel', 'birth_date' => '2014-01-01'])
            ->assertJsonPath('data.student.first_name', 'Juan');

        $requestId = $response->json('data.id');

        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Juan', 'age' => 11]);

        // One pending proposal per student; nothing to change; nothing sent; bad values.
        $this->postJson('/api/students/'.$student->id.'/change-requests', ['last_name' => 'Reyes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['changes']);

        $this->postJson('/api/students/'.$student->id.'/change-requests', ['last_name' => 'Cruz', 'first_name' => 'Juan'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['changes']);

        $this->postJson('/api/students/'.$student->id.'/change-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['changes']);

        $this->postJson('/api/students/'.$student->id.'/change-requests', ['sex' => 'other', 'birth_date' => '2999-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sex', 'birth_date']);

        $this->getJson('/api/student-change-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.student.id', $student->id);

        // The admin applies it.
        $this->forgetGuards();
        $this->signIn($admin);

        $this->getJson('/api/admin/student-change-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.requester.name', 'Tim Teacher')
            ->assertJsonPath('data.0.student.full_name', 'Cruz, Juan');

        $this->getJson('/api/admin/student-change-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/student-change-requests?status=accepted')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/admin/student-change-requests/'.$requestId.'/accept')
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.decider.id', $admin->id)
            ->assertJsonPath('data.student.first_name', 'Juan Miguel')
            ->assertJsonPath('data.student.birth_date', '2014-01-01');

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'first_name' => 'Juan Miguel',
            'last_name' => 'Cruz',
            'age' => Carbon::parse('2014-01-01')->age,
        ]);
        $this->assertSame('2014-01-01', $student->fresh()->birth_date->format('Y-m-d'));

        // Deciding twice is refused; another school's proposal is out of reach.
        $this->postJson('/api/admin/student-change-requests/'.$requestId.'/accept')->assertUnprocessable();
        $this->postJson('/api/admin/student-change-requests/'.StudentChangeRequest::factory()->create()->id.'/accept')->assertNotFound();

        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/student-change-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'accepted')
            ->assertJsonPath('data.0.decider.id', $admin->id);
    }

    public function test_change_requests_can_be_declined_or_withdrawn(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $this->signIn($teacher);
        $student = $this->student(['first_name' => 'Juan']);

        $declined = $this->postJson('/api/students/'.$student->id.'/change-requests', ['first_name' => 'Johnny'])->assertCreated()->json('data.id');

        $this->forgetGuards();
        $this->signIn($admin);

        $this->postJson('/api/admin/student-change-requests/'.$declined.'/decline', ['reason' => 'The birth certificate says Juan.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decline_reason', 'The birth certificate says Juan.');

        $this->postJson('/api/admin/student-change-requests/'.$declined.'/decline')->assertUnprocessable();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Juan']);

        // The teacher sees the reason, may ask again and withdraw.
        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/student-change-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'declined')
            ->assertJsonPath('data.0.decline_reason', 'The birth certificate says Juan.');

        $pending = $this->postJson('/api/students/'.$student->id.'/change-requests', ['first_name' => 'Johnny'])->assertCreated()->json('data.id');

        $this->deleteJson('/api/student-change-requests/'.$pending)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->deleteJson('/api/student-change-requests/'.$pending)->assertUnprocessable();
        $this->deleteJson('/api/student-change-requests/'.StudentChangeRequest::factory()->create()->id)->assertNotFound();

        $this->assertDatabaseHas('student_change_requests', ['id' => $pending, 'status' => 'cancelled']);
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
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonCount(2, 'data.by_subject')
            ->assertJsonCount(1, 'data.by_grade_level')
            ->assertJsonPath('data.by_grade_level.0.id', $grade1->id);

        // One subject narrows everything: its records, the students who take it, and the grade
        // levels / sections the teacher teaches it in (English is Grade 1 only).
        $this->getJson('/api/dashboard/summary?subject_id='.$english1->id)
            ->assertOk()
            ->assertJsonPath('data.grade_levels', 1)
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.tested', 0)
            ->assertJsonCount(1, 'data.by_subject')
            ->assertJsonPath('data.by_subject.0.id', $english1->id)
            ->assertJsonCount(1, 'data.by_grade_level')
            ->assertJsonPath('data.by_grade_level.0.id', $grade1->id)
            ->assertJsonPath('data.by_grade_level.0.total', 1);

        // A student who does not take the subject drops out of the counts.
        $pupil->subjects()->detach($english1->id);

        $this->getJson('/api/dashboard/summary?subject_id='.$english1->id)
            ->assertOk()
            ->assertJsonPath('data.students', 0)
            ->assertJsonPath('data.by_grade_level.0.total', 0);

        $pupil->subjects()->attach($english1->id);

        // The unfiltered figures still cover everything.
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.grade_levels', 2)
            ->assertJsonPath('data.students', 2)
            ->assertJsonCount(2, 'data.by_grade_level');

        // One section: its students and their records only (a sibling section of the teacher's has none).
        $section = $pupil->section;
        $sibling = Section::factory()->create(['grade_level_id' => $grade1->id, 'name' => 'Section Z']);
        TeachingAssignment::query()->create(['section_id' => $sibling->id, 'subject_id' => $math1->id, 'teacher_id' => $this->user->id, 'assigned_by' => $this->user->id]);

        $this->getJson('/api/dashboard/summary?grade_level_id='.$grade1->id)
            ->assertOk()
            ->assertJsonPath('data.sections', 2);

        $this->getJson('/api/dashboard/summary?grade_level_id='.$grade1->id.'&section_id='.$section->id)
            ->assertOk()
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 2);

        $this->getJson('/api/dashboard/summary?section_id='.$sibling->id)
            ->assertOk()
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.students', 0)
            ->assertJsonPath('data.assignments', 0);

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

        $this->getJson('/api/dashboard/summary?section_id='.$theirs->sections()->first()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

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

        // A second student who never got PACE 1001.
        $bystander = $this->student(['grade_level_id' => $grade->id]);

        $this->getJson('/api/dashboard/summary?school_year_id=all')
            ->assertOk()
            ->assertJsonPath('data.students', 2);

        // One PACE: its records only, just that PACE in the PACE count, and only the students who have it.
        $this->getJson('/api/dashboard/summary?school_year_id=all&pace_id='.$paceA->id)
            ->assertOk()
            ->assertJsonPath('data.paces', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.by_grade_level.0.total', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonPath('data.totals.paces', 2);

        $this->getJson('/api/dashboard/summary?school_year_id=all&subject_id='.$math->id.'&pace_id='.$paceB->id)
            ->assertOk()
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonPath('data.tested', 0);

        // A PACE of another branch is rejected.
        $stranger = User::factory()->create();

        $this->getJson('/api/dashboard/summary?pace_id='.$this->pace([], $stranger)->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_id']);
    }

    public function test_dashboard_summary_reports_the_teachers_own_counts_and_recent_students(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 6');
        $section = $this->section($grade);

        $students = Student::factory()->count(6)->create([
            'section_id' => $section->id,
            'school_year_id' => $grade->school_year_id,
            'created_by' => $this->user->id,
        ]);

        foreach (range(1, 3) as $ignored) {
            $this->assignment(['student_id' => $students->first()->id]);
        }

        PaceAssignment::factory()->tested()->create([
            'student_id' => $students->last()->id,
            'subject_id' => $this->subject($grade, 'Math')->id,
            'pace_id' => $this->pace()->id,
            'score' => 70,
            'retake' => true,
        ]);

        // Another school's records must not leak into the summary.
        $stranger = User::factory()->create();
        $this->student([], $stranger);
        $this->pace([], $stranger);
        $this->assignment([], $stranger);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.grade_levels', 1)
            ->assertJsonPath('data.sections', 1)
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
                'by_grade_level' => ['*' => ['id', 'name', 'branch_name', 'total', 'male', 'female']],
            ]])
            ->assertJsonPath('data.by_grade_level.0.total', 6)
            ->assertJsonPath('data.failed.0.failed', true)
            ->assertJsonPath('data.failed.0.retake', true)
            ->assertJsonPath('data.failed.0.score', 70)
            // The branch's catalog: the three PACEs of the records plus the tested one.
            ->assertJsonPath('data.paces', 4)
            ->assertJsonCount(5, 'data.recent_students')
            ->assertJsonPath('data.recent_students.0.id', $students->last()->id)
            ->assertJsonPath('data.recent_students.0.grade_level.name', 'Grade 6')
            ->assertJsonPath('data.totals.sections', 1);
    }

    public function test_guests_cannot_manage_students(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
        $this->postJson('/api/students', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('students', 0);
    }
}
