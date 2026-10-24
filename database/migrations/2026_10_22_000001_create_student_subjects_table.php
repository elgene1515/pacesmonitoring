<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The subjects each student takes. Subjects belong to a grade level, but two
 * students of the same section may take different ones, so enrollment is
 * kept per student. Existing students start out taking every subject of
 * their grade level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'subject_id']);
            $table->index('subject_id');
        });

        $now = now()->toDateTimeString();

        DB::insert(
            'INSERT INTO student_subjects (student_id, subject_id, created_at, updated_at) '
            .'SELECT students.id, subjects.id, ?, ? FROM students '
            .'JOIN sections ON sections.id = students.section_id '
            .'JOIN subjects ON subjects.grade_level_id = sections.grade_level_id',
            [$now, $now]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subjects');
    }
};
