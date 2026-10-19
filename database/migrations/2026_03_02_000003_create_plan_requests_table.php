<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A school admin's request to change their subscription: subscribe to or move
| to another plan (`change`, with a plan) or cancel (`cancel`). The owner
| approves (which records the matching subscription period) or declines.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requests');
    }
};
