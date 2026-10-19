<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A PACE record can be flagged as a retake (the student is redoing the PACE).
| The flag replaces the free-text remarks in the teacher UI; the remarks
| column is kept so nothing already written is lost.
*/
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pace_assignments', function (Blueprint $table) {
            $table->boolean('retake')->default(false)->after('remarks');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pace_assignments', function (Blueprint $table) {
            $table->dropColumn('retake');
        });
    }
};
