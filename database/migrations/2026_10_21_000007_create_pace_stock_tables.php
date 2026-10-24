<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Where the booklets are. `paces.pool_stock` is the branch's pool (what the
| admin has on hand); a holding is what one teacher has of a PACE. Stock
| only enters through the pool (the admin records a delivery) and otherwise
| moves between the pool and teachers, so pool + holdings always add up to
| what the branch received. Every move is logged so the counts can be
| reconciled; a teacher issuing a booklet to a student is not a move, it
| simply reduces their holding as before.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pace_stock_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['pace_id', 'user_id']);
        });

        Schema::create('pace_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            // Signed: a pool adjustment may take booklets away (lost, damaged).
            $table->integer('quantity');
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pace_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['pace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pace_stock_movements');
        Schema::dropIfExists('pace_stock_holdings');
    }
};
