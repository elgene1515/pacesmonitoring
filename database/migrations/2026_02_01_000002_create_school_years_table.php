<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| School years are registered per user ("2026-2027"), one of them flagged as
| current. Students and PACEs remember the school year they were registered
| in; PACE assignments keep their existing school_year label, which must now
| match one of the user's registered school years.
|
| Users who already have records get a school year for the current period
| (June–May) plus one for every school year found on their PACE records, and
| their existing students and PACEs are filed under the current one.
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
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 9);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('grade_level_id')->constrained()->nullOnDelete();
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('number')->constrained()->nullOnDelete();
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
        Schema::table('paces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_year_id');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_year_id');
        });

        Schema::dropIfExists('school_years');
    }

    /**
     * Give every user who already has records a set of school years.
     */
    protected function backfill(): void
    {
        $now = now();
        $startYear = (int) $now->format('n') >= 6 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        $current = sprintf('%d-%d', $startYear, $startYear + 1);

        foreach (DB::table('users')->pluck('id') as $userId) {
            $hasStudents = DB::table('students')->where('created_by', $userId)->exists();
            $hasPaces = DB::table('paces')->where('created_by', $userId)->exists();

            $names = DB::table('pace_assignments')
                ->join('students', 'students.id', '=', 'pace_assignments.student_id')
                ->where('students.created_by', $userId)
                ->distinct()
                ->pluck('pace_assignments.school_year');

            if (! $hasStudents && ! $hasPaces && $names->isEmpty()) {
                continue;
            }

            foreach ($names->push($current)->unique()->sort()->values() as $name) {
                DB::table('school_years')->insert([
                    'user_id' => $userId,
                    'name' => $name,
                    'start_date' => substr($name, 0, 4).'-06-01',
                    'end_date' => substr($name, 5, 4).'-05-31',
                    'is_current' => $name === $current,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $currentId = DB::table('school_years')->where('user_id', $userId)->where('name', $current)->value('id');

            DB::table('students')->where('created_by', $userId)->whereNull('school_year_id')->update(['school_year_id' => $currentId]);
            DB::table('paces')->where('created_by', $userId)->whereNull('school_year_id')->update(['school_year_id' => $currentId]);
        }
    }
};
