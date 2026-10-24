<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's name split into its parts, how to reach them, and when they
 * accepted the Terms of Service and Privacy Policy (and which version).
 * `name` stays as the full name composed from the parts, so every list and
 * greeting keeps working; accounts made before this have only `name` and
 * leave the new columns empty (no acceptance is recorded for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
            $table->string('suffix', 20)->nullable()->after('last_name');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('address', 500)->nullable()->after('phone');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'phone',
                'address',
                'terms_accepted_at',
                'terms_version',
            ]);
        });
    }
};
