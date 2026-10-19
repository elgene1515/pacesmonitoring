<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| When a member last lost their organization (a teacher removed by their
| school). It lets the owner's "accounts not in a school yet" list tell an
| account that never joined a school (without a school since it was created)
| from one that left a school. Null for everybody else.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('left_organization_at')->nullable()->after('current_school_year_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('left_organization_at');
        });
    }
};
