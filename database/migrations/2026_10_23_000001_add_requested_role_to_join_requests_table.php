<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A join request says which role the person wants in the school: teacher (as
 * before) or administrator (an admin-type account joining a school that is
 * already registered). The accepting admin has the final say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('join_requests', function (Blueprint $table) {
            $table->string('requested_role', 20)->default('teacher')->after('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('join_requests', function (Blueprint $table) {
            $table->dropColumn('requested_role');
        });
    }
};
