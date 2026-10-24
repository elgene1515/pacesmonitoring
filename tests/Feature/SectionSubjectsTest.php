<?php

namespace Tests\Feature;

use App\Models\Section;

/**
 * Not every section of a grade level offers every subject: Section 1 may have
 * Filipino while Section 2 does not.
 */
class SectionSubjectsTest extends ApiTestCase
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

    /** @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\GradeLevel, 3: Section, 4: Section} */
    private function setUpSections(): array
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $gradeLevel = $this->gradeLevel('Grade 1', $admin, ['English', 'Math', 'Filipino']);
        $one = $this->section($gradeLevel, $admin);
        $two = Section::factory()->create(['grade_level_id' => $gradeLevel->id, 'name' => 'Section 2']);

        return [$admin, $teacher, $gradeLevel, $one, $two];
    }

    public function test_new_sections_and_subjects_are_offered_everywhere_by_default(): void
    {
        [$admin, , $gradeLevel, $one] = $this->setUpSections();

        $this->signIn($admin);

        // A new section offers all three subjects.
        $section = $this->postJson("/api/admin/grade-levels/{$gradeLevel->id}/sections", ['name' => 'Section 3'])
            ->assertCreated()
            ->assertJsonCount(3, 'data.subject_ids');
        $this->assertSame(3, Section::query()->findOrFail($section->json('data.id'))->subjects()->count());

        // A new subject is offered by every existing section.
        $this->postJson("/api/admin/grade-levels/{$gradeLevel->id}/subjects", ['name' => 'Science'])->assertCreated();
        $this->assertSame(4, $one->subjects()->count());
        $this->assertSame(4, Section::query()->findOrFail($section->json('data.id'))->subjects()->count());
    }

    public function test_a_section_can_stop_offering_a_subject(): void
    {
        [$admin, , $gradeLevel, $one, $two] = $this->setUpSections();
        $filipino = $this->subject($gradeLevel, 'Filipino');

        $this->signIn($admin);

        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")
            ->assertOk()
            ->assertJsonCount(2, 'data.subject_ids');

        $this->assertFalse($two->fresh()->offers($filipino));
        $this->assertTrue($one->fresh()->offers($filipino));

        // It can be offered again.
        $this->putJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")
            ->assertOk()
            ->assertJsonCount(3, 'data.subject_ids');
    }

    public function test_a_subject_with_a_teacher_or_students_cannot_be_withdrawn(): void
    {
        [$admin, $teacher, $gradeLevel, , $two] = $this->setUpSections();
        $filipino = $this->subject($gradeLevel, 'Filipino');
        $english = $this->subject($gradeLevel, 'English');

        $this->assign($teacher, $two, $filipino);
        $student = $this->student(['section_id' => $two->id], $admin);
        $student->subjects()->sync([$english->id]);

        $this->signIn($admin);

        // Filipino has a teacher in Section 2.
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        // English has no teacher but a student takes it.
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$english->id}/offer")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $this->assertSame(3, $two->subjects()->count());

        // Once nobody teaches or takes it, it can go.
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/teacher")->assertNoContent();
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")->assertOk();
    }

    public function test_a_subject_a_section_does_not_offer_cannot_be_taught_or_requested(): void
    {
        [$admin, $teacher, $gradeLevel, , $two] = $this->setUpSections();
        $filipino = $this->subject($gradeLevel, 'Filipino');

        $this->signIn($admin);
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")->assertOk();

        $this->putJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/teacher", ['teacher_id' => $teacher->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $this->forgetGuards();
        $this->signIn($teacher);
        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $two->id, 'subject_id' => $filipino->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_students_can_only_take_subjects_their_section_offers(): void
    {
        [$admin, , $gradeLevel, , $two] = $this->setUpSections();
        $filipino = $this->subject($gradeLevel, 'Filipino');
        $english = $this->subject($gradeLevel, 'English');

        $this->signIn($admin);
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")->assertOk();

        // The default is what the section offers.
        $response = $this->postJson('/api/admin/students', $this->details(['section_id' => $two->id]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.subjects');
        $this->assertEqualsCanonicalizing(['English', 'Math'], collect($response->json('data.subjects'))->pluck('name')->all());

        // Filipino is refused for Section 2.
        $this->postJson('/api/admin/students', $this->details(['section_id' => $two->id, 'subject_ids' => [$english->id, $filipino->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_ids']);
    }

    public function test_moving_a_student_to_a_section_without_a_subject_drops_it(): void
    {
        [$admin, , $gradeLevel, $one, $two] = $this->setUpSections();
        $filipino = $this->subject($gradeLevel, 'Filipino');

        $this->signIn($admin);
        $this->deleteJson("/api/admin/sections/{$two->id}/subjects/{$filipino->id}/offer")->assertOk();

        $student = $this->student(['section_id' => $one->id], $admin);
        $this->assertSame(3, $student->subjects()->count());

        $this->putJson("/api/admin/students/{$student->id}", $this->details(['section_id' => $two->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data.subjects');
        $this->assertFalse($student->fresh()->subjects()->whereKey($filipino->id)->exists());
    }
}
