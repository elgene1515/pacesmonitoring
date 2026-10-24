<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;

/**
 * The optional student details: school ID number (unique within a school),
 * contact, emergency contact and health information, on enrollment, admin
 * edits and teacher proposals.
 */
class StudentDetailsTest extends ApiTestCase
{
    private const BASE = [
        'first_name' => 'Pia',
        'last_name' => 'Cruz',
        'birth_date' => '2019-05-05',
        'age' => 7,
        'sex' => 'female',
    ];

    private const DETAILS = [
        'student_number' => '2026-0001',
        'email' => 'pia.cruz@example.com',
        'mobile' => '+63 917 123 4567',
        'address' => '12 Hill St, Quezon City',
        'guardian_name' => 'Rosa Cruz',
        'guardian_relationship' => 'Mother',
        'guardian_phone' => '(02) 8123-4567',
        'guardian_email' => 'rosa.cruz@example.com',
        'blood_type' => 'O+',
        'medical_notes' => "Peanut allergy.\nUses an inhaler.",
    ];

    public function test_an_admin_enrolls_and_edits_a_student_with_every_detail(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $this->signIn($admin);
        $section = $this->section($this->gradeLevel('Grade 1', $admin, ['Math']), $admin);

        $response = $this->postJson('/api/admin/students', self::BASE + self::DETAILS + ['section_id' => $section->id])->assertCreated();

        foreach (self::DETAILS as $field => $value) {
            $response->assertJsonPath("data.{$field}", $value);
        }

        $id = $response->json('data.id');

        // The details are on the student page, and the ID number finds the student.
        $this->getJson('/api/admin/students/'.$id)->assertOk()->assertJsonPath('data.guardian_phone', '(02) 8123-4567');
        $this->getJson('/api/admin/students?search=2026-0001')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        // Saving again with the same ID number is fine (it is the student's own); blanks clear a detail.
        $this->putJson('/api/admin/students/'.$id, ['section_id' => $section->id, 'medical_notes' => '  ', 'blood_type' => ''] + self::BASE + self::DETAILS)
            ->assertOk()
            ->assertJsonPath('data.student_number', '2026-0001')
            ->assertJsonPath('data.medical_notes', null)
            ->assertJsonPath('data.blood_type', null);
    }

    public function test_every_detail_is_optional_and_validated_when_given(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $this->signIn($admin);
        $section = $this->section($this->gradeLevel('Grade 1', $admin, ['Math']), $admin);

        $this->postJson('/api/admin/students', self::BASE + ['section_id' => $section->id])
            ->assertCreated()
            ->assertJsonPath('data.student_number', null)
            ->assertJsonPath('data.guardian_name', null);

        $this->postJson('/api/admin/students', self::BASE + [
            'section_id' => $section->id,
            'email' => 'not-an-email',
            'guardian_email' => 'also wrong',
            'mobile' => 'call me',
            'guardian_phone' => '12',
            'blood_type' => 'Z+',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'guardian_email', 'mobile', 'guardian_phone', 'blood_type']);
    }

    public function test_the_id_number_is_unique_within_a_school_only(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $this->signIn($admin);
        $grade = $this->gradeLevel('Grade 1', $admin, ['Math']);
        $section = $this->section($grade, $admin);
        $other = Section::factory()->create(['grade_level_id' => $grade->id, 'name' => 'Section 2']);

        $first = $this->postJson('/api/admin/students', self::BASE + ['section_id' => $section->id, 'student_number' => 'S-100'])->assertCreated()->json('data.id');

        // Another student of the same school, in any section: refused.
        $this->postJson('/api/admin/students', self::BASE + ['section_id' => $other->id, 'student_number' => 'S-100'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_number']);

        $second = $this->postJson('/api/admin/students', self::BASE + ['section_id' => $other->id, 'student_number' => 'S-101'])->assertCreated()->json('data.id');

        $this->putJson('/api/admin/students/'.$second, self::BASE + ['section_id' => $other->id, 'student_number' => 'S-100'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_number']);

        // Another school may use the same number.
        $elsewhere = $this->organization();
        $otherAdmin = $this->admin($elsewhere);
        $this->forgetGuards();
        $this->signIn($otherAdmin);
        $theirSection = $this->section($this->gradeLevel('Grade 1', $otherAdmin, ['Math']), $otherAdmin);

        $this->postJson('/api/admin/students', self::BASE + ['section_id' => $theirSection->id, 'student_number' => 'S-100'])->assertCreated();

        $this->assertSame('S-100', Student::query()->find($first)->student_number);
    }

    public function test_a_teacher_enrolls_with_details_and_proposes_changes_to_them(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $admin = $this->admin($organization);
        $grade = $this->gradeLevel('Grade 1', $teacher, ['Math']);
        $section = $this->section($grade, $teacher);

        $this->signIn($teacher);

        $id = $this->postJson('/api/students', self::BASE + self::DETAILS + ['section_id' => $section->id])
            ->assertCreated()
            ->assertJsonPath('data.blood_type', 'O+')
            ->json('data.id');

        $this->getJson('/api/students/'.$id)->assertOk()->assertJsonPath('data.guardian_name', 'Rosa Cruz');

        // The teacher's list shows the ID number and finds the student by it (or by name).
        $this->postJson('/api/students', ['first_name' => 'Ben', 'section_id' => $section->id, 'student_number' => '2026-0099'] + self::BASE)->assertCreated();

        $this->getJson('/api/students?search=2026-0001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.student_number', '2026-0001');
        $this->getJson('/api/students?search=0099')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Ben');
        $this->getJson('/api/students?search=Cruz')->assertOk()->assertJsonCount(2, 'data');

        // A new phone number for the emergency contact goes to the admin as a proposal.
        $this->postJson('/api/students/'.$id.'/change-requests', ['guardian_phone' => '0918 765 4321', 'medical_notes' => 'Peanut allergy.'])
            ->assertCreated()
            ->assertJsonPath('data.changes.guardian_phone', '0918 765 4321')
            ->assertJsonPath('data.changes.medical_notes', 'Peanut allergy.');

        $this->assertDatabaseHas('students', ['id' => $id, 'guardian_phone' => '(02) 8123-4567']);

        $this->postJson('/api/students/'.$id.'/change-requests', ['guardian_email' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guardian_email']);

        $requestId = \App\Models\StudentChangeRequest::query()->where('student_id', $id)->value('id');

        $this->forgetGuards();
        $this->signIn($admin);
        $this->postJson('/api/admin/student-change-requests/'.$requestId.'/accept')->assertOk();

        $this->assertDatabaseHas('students', ['id' => $id, 'guardian_phone' => '0918 765 4321', 'medical_notes' => 'Peanut allergy.', 'guardian_name' => 'Rosa Cruz']);
    }

    public function test_the_section_roster_carries_the_id_number_and_the_average_score(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $grade = $this->gradeLevel('Grade 1', $teacher, ['Math']);
        $section = $this->section($grade, $teacher);
        $math = $this->subject($grade, 'Math');

        $this->signIn($teacher);

        $scored = $this->postJson('/api/students', ['first_name' => 'Ana', 'section_id' => $section->id, 'student_number' => 'R-1'] + self::BASE)->assertCreated()->json('data.id');
        $this->postJson('/api/students', ['first_name' => 'Ben', 'section_id' => $section->id, 'student_number' => 'R-2'] + self::BASE)->assertCreated();

        // Two records, one scored 90 and one not yet scored: the average counts the unscored as 0.
        PaceAssignment::factory()->tested()->create(['student_id' => $scored, 'subject_id' => $math->id, 'school_year' => '2026-2027', 'score' => 90]);
        PaceAssignment::factory()->create(['student_id' => $scored, 'subject_id' => $math->id, 'school_year' => '2026-2027']);

        $roster = $this->getJson('/api/sections/'.$section->id)->assertOk()->json('data.students');

        $this->assertSame(['R-1', 'R-2'], array_column($roster, 'student_number'));
        $this->assertEquals(45.0, $roster[0]['average_score']);
        $this->assertSame(2, $roster[0]['assignments_count']);
        $this->assertNull($roster[1]['average_score']);
    }

    public function test_a_teacher_cannot_propose_an_id_number_another_student_has(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $grade = $this->gradeLevel('Grade 1', $teacher, ['Math']);
        $section = $this->section($grade, $teacher);

        $this->signIn($teacher);

        $this->postJson('/api/students', self::BASE + ['section_id' => $section->id, 'student_number' => 'A-1'])->assertCreated();
        $id = $this->postJson('/api/students', self::BASE + ['section_id' => $section->id, 'student_number' => 'A-2'])->assertCreated()->json('data.id');

        $this->postJson('/api/students/'.$id.'/change-requests', ['student_number' => 'A-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_number']);

        $this->postJson('/api/students/'.$id.'/change-requests', ['student_number' => 'A-3'])->assertCreated();
    }
}
