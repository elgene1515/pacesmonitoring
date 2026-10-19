<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| School years now belong to an organization (registered by its admins) so
| every teacher of a school works from the same list. `user_id` stays as the
| user who registered the year; labels are unique per organization.
|
| Existing installations: the per-user school years of each organization are
| merged by label (the oldest row survives, grade levels and students are
| re-pointed to it), each teacher's former current year becomes their personal
| current year, and the label most teachers had flagged as current becomes the
| organization's default.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // MySQL backs the user_id foreign key with the old composite unique index,
        // so a plain index on user_id has to exist before that one can go.
        Schema::table('school_years', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropUnique(['user_id', 'name']);
        });

        $this->backfill();

        Schema::table('school_years', function (Blueprint $table) {
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'name']);
            $table->dropConstrainedForeignId('organization_id');
        });

        // Merged rows cannot be split back into per-user rows; the per-user
        // uniqueness is only restored when the data still allows it.
        $duplicates = DB::table('school_years')
            ->selectRaw('user_id, name, count(*) as total')
            ->groupBy('user_id', 'name')
            ->having('total', '>', 1)
            ->exists();

        if (! $duplicates) {
            Schema::table('school_years', function (Blueprint $table) {
                $table->unique(['user_id', 'name']);
                $table->dropIndex(['user_id']);
            });
        }
    }

    /**
     * Merge each organization's per-user school years into one list.
     */
    protected function backfill(): void
    {
        $rows = DB::table('school_years')
            ->join('users', 'users.id', '=', 'school_years.user_id')
            ->whereNotNull('users.organization_id')
            ->select('school_years.*', 'users.organization_id as org_id')
            ->orderBy('school_years.id')
            ->get();

        foreach ($rows->groupBy('org_id') as $organizationId => $organizationRows) {
            $votes = [];
            $survivors = [];

            foreach ($organizationRows->groupBy('name') as $name => $sameName) {
                $survivor = $sameName->first();
                $survivors[$name] = $survivor->id;
                $votes[$name] = $sameName->where('is_current', true)->count();

                DB::table('school_years')->where('id', $survivor->id)->update([
                    'organization_id' => $organizationId,
                    'start_date' => $survivor->start_date ?? $sameName->firstWhere('start_date', '!=', null)?->start_date,
                    'end_date' => $survivor->end_date ?? $sameName->firstWhere('end_date', '!=', null)?->end_date,
                    'is_current' => false,
                ]);

                // Whatever a teacher had flagged as current stays their personal current year.
                foreach ($sameName->where('is_current', true) as $row) {
                    DB::table('users')->where('id', $row->user_id)->update(['current_school_year_id' => $survivor->id]);
                }

                $duplicateIds = $sameName->skip(1)->pluck('id')->all();

                if ($duplicateIds !== []) {
                    DB::table('grade_levels')->whereIn('school_year_id', $duplicateIds)->update(['school_year_id' => $survivor->id]);
                    DB::table('students')->whereIn('school_year_id', $duplicateIds)->update(['school_year_id' => $survivor->id]);
                    DB::table('school_years')->whereIn('id', $duplicateIds)->delete();
                }
            }

            if ($survivors === []) {
                continue;
            }

            // The organization's default: the label most teachers had as current, newest label on a tie.
            $names = array_keys($survivors);
            usort($names, static fn (string $a, string $b): int => [$votes[$b], $b] <=> [$votes[$a], $a]);

            DB::table('school_years')->where('id', $survivors[$names[0]])->update(['is_current' => true]);
        }
    }
};
