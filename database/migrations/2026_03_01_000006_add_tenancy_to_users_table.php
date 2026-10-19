<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Users get a role (owner / admin / teacher) and belong to an organization and
| branch. The former `is_admin` flag (the system-wide admin) becomes the
| `owner` role.
|
| Existing installations: every other user is attached, as a teacher, to a
| "Default Organization" with a Main branch and a one-year Starter
| subscription, so nothing becomes unreachable. The owner can rename that
| organization and promote one of its teachers to admin from the owner
| dashboard. A clean install has nothing to backfill.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('teacher')->after('password');
            $table->foreignId('organization_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            // The teacher's own "current" school year (overrides the organization's default).
            $table->foreignId('current_school_year_id')->nullable()->after('branch_id')->constrained('school_years')->nullOnDelete();
        });

        $this->backfill();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        DB::table('users')->where('role', 'owner')->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_school_year_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('role');
        });
    }

    /**
     * Map the old admin flag to the owner role and give every other existing
     * user a home organization.
     */
    protected function backfill(): void
    {
        DB::table('users')->where('is_admin', true)->update(['role' => 'owner']);

        $legacyUserIds = DB::table('users')->where('is_admin', false)->pluck('id');

        if ($legacyUserIds->isEmpty()) {
            return;
        }

        $now = now();

        do {
            $code = 'SCH-'.strtoupper(Str::random(6));
        } while (DB::table('organizations')->where('code', $code)->exists());

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Default Organization',
            'code' => $code,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $branchId = DB::table('branches')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $planId = DB::table('plans')->where('slug', 'starter')->value('id')
            ?? DB::table('plans')->orderBy('sort_order')->value('id');

        if ($planId !== null) {
            DB::table('subscriptions')->insert([
                'organization_id' => $organizationId,
                'plan_id' => $planId,
                'status' => 'active',
                'starts_at' => $now->toDateString(),
                'ends_at' => $now->copy()->addYear()->toDateString(),
                'notes' => 'Created automatically when upgrading an existing installation.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('users')->whereIn('id', $legacyUserIds)->update([
            'role' => 'teacher',
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
        ]);
    }
};
