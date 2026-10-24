<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A student is enrolled in a section, not registered to one teacher. The
| grade level is the section's; `created_by` stays as the person who
| registered the student (admin or teacher) and no longer limits who may
| see them. Written for a fresh database (see the grade levels migration).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('section_id')->after('sex')->constrained()->restrictOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('grade_level_id')->nullable()->after('sex')->constrained()->restrictOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
