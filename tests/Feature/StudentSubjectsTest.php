<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\Student;

/**
 * Subjects belong to a grade level, but each student takes their own: two
 * students of one section may study different subjects.
 */
class StudentSubjectsTest extends ApiTestCase
{
    private function details(array $extra = []): array
    {
        return $extra + [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'birth_date' => '2015-03-14',
            'age' => 11,
            'sex' => 'male',
        ];
    }

    public function test_a_new_student_takes_every_subject_of_the_grade_level_by_default(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $admin, ['English', 'Math', 'Filipino']);
        $section = $this->section($gradeLevel, $admin);

        $this->signIn($admin);

        $response = $this->postJson('/api/admin/students', $this->details(['section_id' => $section->id]))
            ->assertCreated()
            ->assertJsonCount(3, 'data.subjects');

        $this->assertEqualsCanonicalizing(
            ['English', 'Filipino', 'Math'],
            collect($response->json('data.subjects'))->pluck('name')->all()
        );
    }

    public function test_students_of_one_section_can_take_different_subjects(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $admin, ['English', 'Math', 'Filipino', 'Reading and Writing']);
        $section = $this->section($gradeLevel, $admin);
        $english = $this->subject($gradeLevel, 'English');
        $math = $this->subject($gradeLevel, 'Math');
        $filipino = $this->subject($gradeLevel, 'Filipino');
        $reading = $this->subject($gradeLevel, 'Reading and Writing');

        $this->signIn($admin);

        $first = $this->postJson('/api/admin/students', $this->details(['section_id' => $section->id, 'subject_ids' => [$english->id, $math->id, $filipino->id]]))
            ->assertCreated()
            ->assertJsonCount(3, 'data.subjects');
        $second = $this->postJson('/api/admin/students', $this->details(['first_name' => 'Ana', 'section_id' => $section->id, 'subject_ids' => [$english->id, $math->id, $reading->id]]))
            ->assertCreated()
            ->assertJsonCount(3, 'data.subjects');

        $names = fn ($response) => collect($response->json('data.subjects'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['English', 'Filipino', 'Math'], $names($first));
        $this->assertSame(['English', 'Math', 'Reading and Writing'], $names($second));

        // Both are in the same section.
        $this->assertSame($section->id, $first->json('data.section_id'));
        $this->assertSame($section->id, $second->json('data.section_id'));

        // Subjects of another grade level are refused.
        $other = $this->gradeLevel('Grade 2', $admin, ['Science']);
        $this->postJson('/api/admin/students', $this->details(['section_id' => $section->id, 'subject_ids' => [$this->subject($other, 'Science')->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_ids']);
    }

    public function test_pace_records_can_only_be_assigned_under_a_subject_the_student_takes(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $teacher, ['English', 'Math', 'Filipino']);
        $section = $this->section($gradeLevel, $teacher);
        $english = $this->subject($gradeLevel, 'English');
        $filipino = $this->subject($gradeLevel, 'Filipino');
        $pace = $this->pace(['my_stock' => 5], $teacher);

        $student = $this->student(['section_id' => $section->id], $teacher);
        $student->subjects()->sync([$english->id]);

        $this->signIn($teacher);

        $payload = fn ($subject) => [
            'subject_id' => $subject->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ];

        $this->postJson("/api/students/{$student->id}/assignments", $payload($filipino))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $this->postJson("/api/students/{$student->id}/assignments", $payload($english))->assertCreated();
    }

    public function test_a_teacher_only_sees_the_students_taking_a_subject_they_teach(): void
    {
        $organization = $this->organization();
        $english = $this->teacher($organization);
        $reading = $this->teacher($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $english, ['English', 'Reading and Writing']);
        $section = $this->section($gradeLevel, $english);
        $readingSubject = $this->subject($gradeLevel, 'Reading and Writing');
        $englishSubject = $this->subject($gradeLevel, 'English');

        // The reading teacher takes over Reading and Writing in the section.
        $this->assign($reading, $section, $readingSubject);

        $both = $this->student(['section_id' => $section->id, 'first_name' => 'Both'], $english);
        $onlyEnglish = $this->student(['section_id' => $section->id, 'first_name' => 'OnlyEnglish'], $english);
        $both->subjects()->sync([$englishSubject->id, $readingSubject->id]);
        $onlyEnglish->subjects()->sync([$englishSubject->id]);

        $this->signIn($english);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/sections/{$section->id}")
            ->assertOk()
            ->assertJsonPath('data.students_count', 2)
            ->assertJsonCount(2, 'data.students');

        $this->forgetGuards();
        $this->signIn($reading);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Both');
        $this->getJson("/api/sections/{$section->id}")
            ->assertOk()
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonCount(1, 'data.students');
        $this->getJson("/api/students/{$onlyEnglish->id}")->assertNotFound();
        $this->getJson("/api/students/{$both->id}")->assertOk();
    }

    public function test_a_teacher_enrolling_a_student_must_keep_a_subject_they_teach(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $other = $this->teacher($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $teacher, ['English', 'Math']);
        $section = $this->section($gradeLevel, $teacher);
        $english = $this->subject($gradeLevel, 'English');
        $math = $this->subject($gradeLevel, 'Math');

        // Somebody else teaches Math here.
        $this->assign($other, $section, $math);

        $this->signIn($teacher);

        $this->postJson('/api/students', $this->details(['section_id' => $section->id, 'subject_ids' => [$math->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_ids']);

        $this->postJson('/api/students', $this->details(['section_id' => $section->id, 'subject_ids' => [$english->id, $math->id]]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.subjects');
    }

    public function test_the_admin_changes_a_students_subjects_but_not_those_with_records(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $teacher, ['English', 'Math', 'Filipino']);
        $section = $this->section($gradeLevel, $teacher);
        $english = $this->subject($gradeLevel, 'English');
        $math = $this->subject($gradeLevel, 'Math');
        $filipino = $this->subject($gradeLevel, 'Filipino');

        $student = $this->student(['section_id' => $section->id], $teacher);
        PaceAssignment::factory()->create(['student_id' => $student->id, 'subject_id' => $math->id, 'pace_id' => $this->pace([], $teacher)->id]);

        $this->signIn($admin);

        $details = $this->details(['section_id' => $section->id]);

        // Dropping Filipino (no records) is fine.
        $this->putJson("/api/admin/students/{$student->id}", $details + ['subject_ids' => [$english->id, $math->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.subjects');

        // Dropping Math, which has a record, is refused.
        $this->putJson("/api/admin/students/{$student->id}", $details + ['subject_ids' => [$english->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_ids']);
        $this->assertSame(2, $student->subjects()->count());

        // Leaving the subjects out keeps them.
        $this->putJson("/api/admin/students/{$student->id}", $details + ['first_name' => 'Renamed'])
            ->assertOk()
            ->assertJsonCount(2, 'data.subjects');

        // Adding one back works.
        $this->putJson("/api/admin/students/{$student->id}", $details + ['subject_ids' => [$english->id, $math->id, $filipino->id]])
            ->assertOk()
            ->assertJsonCount(3, 'data.subjects');
    }

    public function test_moving_a_student_to_another_grade_level_replaces_their_subjects(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $gradeOne = $this->gradeLevel('Grade 1', $admin, ['English', 'Math']);
        $gradeTwo = $this->gradeLevel('Grade 2', $admin, ['Science', 'History', 'Art']);
        $sectionOne = $this->section($gradeOne, $admin);
        $sectionTwo = $this->section($gradeTwo, $admin);

        $student = $this->student(['section_id' => $sectionOne->id], $admin);
        $this->assertSame(2, $student->subjects()->count());

        $this->signIn($admin);

        $details = $this->details();

        $this->putJson("/api/admin/students/{$student->id}", $details + ['section_id' => $sectionTwo->id])
            ->assertOk()
            ->assertJsonCount(3, 'data.subjects');

        $names = Student::query()->findOrFail($student->id)->subjects()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Art', 'History', 'Science'], $names);
    }
}
