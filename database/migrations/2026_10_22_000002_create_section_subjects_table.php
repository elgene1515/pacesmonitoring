<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The subjects each section offers. Subjects belong to a grade level, but not
 * every section of it offers all of them (e.g. Section 1 has Filipino,
 * Section 2 does not). Existing sections start out offering every subject of
 * their grade level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['section_id', 'subject_id']);
            $table->index('subject_id');
        });

        $now = now()->toDateTimeString();

        DB::insert(
            'INSERT INTO section_subjects (section_id, subject_id, created_at, updated_at) '
            .'SELECT sections.id, subjects.id, ?, ? FROM sections '
            .'JOIN subjects ON subjects.grade_level_id = sections.grade_level_id',
            [$now, $now]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('section_subjects');
    }
};
