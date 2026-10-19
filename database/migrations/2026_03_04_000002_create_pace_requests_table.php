<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A teacher's request to their school admin for PACE booklets: which PACE
| (optionally one they already registered), how many and by when. The admin
| edits it if needed, then marks it given (adding the booklets to the linked
| PACE's stock) or declines it. Requests are kept as history.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pace_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pace_number', 50);
            $table->string('pace_name');
            $table->unsignedInteger('quantity');
            $table->date('needed_by')->nullable();
            $table->string('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pace_requests');
    }
};
