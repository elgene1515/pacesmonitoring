<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pace_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pace_id')->constrained()->cascadeOnDelete();
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('quarter');
            $table->date('issued_date')->nullable();
            $table->date('date_tested')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->index(
                ['student_id', 'school_year', 'quarter', 'subject_id'],
                'pace_assignments_student_year_quarter_subject_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pace_assignments');
    }
};
