<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Every record now belongs to the user who created it. Grade levels gain an
| owner column, and the uniqueness of grade level names and PACE numbers is
| enforced per user instead of globally.
|
| Rows that existed before this migration (seeded grade levels, students and
| PACEs with no creator) are handed to the first registered user so nothing
| becomes unreachable. A clean install (`php artisan migrate:fresh`) has
| nothing to backfill.
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
        Schema::table('grade_levels', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique('grade_levels_name_unique');
            $table->unique(['user_id', 'name']);
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->dropUnique('paces_number_unique');
            $table->unique(['created_by', 'number']);
        });

        $this->backfillOwners();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('paces', function (Blueprint $table) {
            $table->dropUnique(['created_by', 'number']);
            $table->unique('number');
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'name']);

            $table->dropConstrainedForeignId('user_id');

            $table->unique('name');
        });
    }

    /**
     * Attach ownerless rows to a user so existing data stays reachable.
     */
    protected function backfillOwners(): void
    {
        $firstUserId = DB::table('users')->orderBy('id')->value('id');

        if ($firstUserId === null) {
            return;
        }

        DB::table('students')->whereNull('created_by')->update(['created_by' => $firstUserId]);
        DB::table('paces')->whereNull('created_by')->update(['created_by' => $firstUserId]);

        // A grade level goes to the user whose students are enrolled in it, otherwise to the first user.
        $gradeLevelIds = DB::table('grade_levels')->whereNull('user_id')->pluck('id');

        foreach ($gradeLevelIds as $gradeLevelId) {
            $ownerId = DB::table('students')
                ->where('grade_level_id', $gradeLevelId)
                ->whereNotNull('created_by')
                ->orderBy('id')
                ->value('created_by');

            DB::table('grade_levels')
                ->where('id', $gradeLevelId)
                ->update(['user_id' => $ownerId ?? $firstUserId]);
        }
    }
};
