<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More about each student, all optional: the school's own ID number, how to
 * reach the student, who to call in an emergency, and what the school needs
 * to know about their health. The ID number is unique within a school (checked
 * when saving; students reach their school through the section).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('student_number', 50)->nullable()->after('id');
            $table->string('email')->nullable()->after('sex');
            $table->string('mobile', 30)->nullable()->after('email');
            $table->string('address', 500)->nullable()->after('mobile');
            $table->string('guardian_name')->nullable()->after('address');
            $table->string('guardian_relationship', 50)->nullable()->after('guardian_name');
            $table->string('guardian_phone', 30)->nullable()->after('guardian_relationship');
            $table->string('guardian_email')->nullable()->after('guardian_phone');
            $table->string('blood_type', 10)->nullable()->after('guardian_email');
            $table->text('medical_notes')->nullable()->after('blood_type');

            $table->index('student_number');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['student_number']);
            $table->dropColumn([
                'student_number',
                'email',
                'mobile',
                'address',
                'guardian_name',
                'guardian_relationship',
                'guardian_phone',
                'guardian_email',
                'blood_type',
                'medical_notes',
            ]);
        });
    }
};
