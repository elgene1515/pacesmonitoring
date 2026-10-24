<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a student has been enrolled over time: one row per stay in a section
 * (opened when the student is enrolled or moved, closed when they move on).
 * The names are copied onto the row so the history survives a section or
 * grade level being deleted later. Existing students start with their
 * current section as an open row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section_name');
            $table->string('grade_level_name');
            $table->string('school_year_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'started_on']);
            $table->index('section_id');
        });

        $now = now()->toDateTimeString();

        DB::insert(
            'INSERT INTO student_enrollments (student_id, section_id, grade_level_id, school_year_id, branch_id, '
            .'section_name, grade_level_name, school_year_name, branch_name, started_on, ended_on, moved_by, created_at, updated_at) '
            .'SELECT students.id, sections.id, grade_levels.id, grade_levels.school_year_id, grade_levels.branch_id, '
            .'sections.name, grade_levels.name, school_years.name, branches.name, DATE(students.created_at), NULL, NULL, ?, ? '
            .'FROM students '
            .'JOIN sections ON sections.id = students.section_id '
            .'JOIN grade_levels ON grade_levels.id = sections.grade_level_id '
            .'LEFT JOIN school_years ON school_years.id = grade_levels.school_year_id '
            .'LEFT JOIN branches ON branches.id = grade_levels.branch_id',
            [$now, $now]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
