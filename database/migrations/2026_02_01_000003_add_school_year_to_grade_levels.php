<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Grade levels (and therefore their subjects) are registered under one school
| year, so a user may run a different set of grade levels and subjects every
| school year. Names stay unique per user *within* a school year.
|
| Existing grade levels are filed under their owner's current school year; a
| user who has grade levels but no school year yet gets one for the current
| period (June–May) first. Students follow their grade level's school year.
*/
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('grade_levels', 'school_year_id')) {
            Schema::table('grade_levels', function (Blueprint $table) {
                $table->foreignId('school_year_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            });
        }

        // MySQL backs the user_id foreign key with the old composite index, so the
        // replacement (also starting with user_id) must exist before the old one goes.
        Schema::table('grade_levels', function (Blueprint $table) {
            $table->unique(['user_id', 'school_year_id', 'name']);
            $table->dropUnique('grade_levels_user_id_name_unique');
        });

        $this->backfill();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'school_year_id', 'name']);

            $table->dropConstrainedForeignId('school_year_id');

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * File every existing grade level under its owner's current school year.
     */
    protected function backfill(): void
    {
        $now = now();
        $startYear = (int) $now->format('n') >= 6 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        $currentName = sprintf('%d-%d', $startYear, $startYear + 1);

        $owners = DB::table('grade_levels')->whereNull('school_year_id')->distinct()->pluck('user_id');

        foreach ($owners as $userId) {
            $yearId = DB::table('school_years')->where('user_id', $userId)->where('is_current', true)->value('id')
                ?? DB::table('school_years')->where('user_id', $userId)->orderByDesc('name')->value('id');

            if ($yearId === null) {
                $yearId = DB::table('school_years')->insertGetId([
                    'user_id' => $userId,
                    'name' => $currentName,
                    'start_date' => $startYear.'-06-01',
                    'end_date' => ($startYear + 1).'-05-31',
                    'is_current' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('grade_levels')
                ->where('user_id', $userId)
                ->whereNull('school_year_id')
                ->update(['school_year_id' => $yearId]);
        }

        // A student's school year is the school year of their grade level.
        $pairs = DB::table('grade_levels')->whereNotNull('school_year_id')->pluck('school_year_id', 'id');

        foreach ($pairs as $gradeLevelId => $yearId) {
            DB::table('students')
                ->where('grade_level_id', $gradeLevelId)
                ->where(fn ($query) => $query->whereNull('school_year_id')->orWhere('school_year_id', '!=', $yearId))
                ->update(['school_year_id' => $yearId]);
        }
    }
};
