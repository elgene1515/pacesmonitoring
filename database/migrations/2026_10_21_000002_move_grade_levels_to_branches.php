<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Grade levels belong to a branch (set up by the school's admins) instead of
| to one teacher: every teacher assigned to a section of the grade level
| works from the same curriculum. `created_by` only records who set it up.
|
| Written for a fresh database (`php artisan migrate:fresh`): the ownership
| model changed while the project was still in development, so teacher-owned
| rows are not carried over.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_levels', function (Blueprint $table) {
            $table->foreignId('branch_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->after('school_year_id')->constrained('users')->nullOnDelete();
        });

        // MySQL backs the user_id foreign key with the composite unique index,
        // so the key goes first, then the index, then the column.
        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'school_year_id', 'name']);
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->unique(['branch_id', 'school_year_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'school_year_id', 'name']);
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->unique(['user_id', 'school_year_id', 'name']);
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
