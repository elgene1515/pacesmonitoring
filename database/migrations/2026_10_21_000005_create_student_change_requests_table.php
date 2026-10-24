<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A teacher's proposed edit to a student's own details (name, birth date,
| sex, ...). `changes` holds only the fields being changed; the admin
| accepts (the change is applied) or declines it. Admins edit students
| directly and never need a request.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->json('changes');
            $table->string('status', 20)->default('pending');
            $table->string('decline_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_change_requests');
    }
};
