<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| PACE numbers are shared across different PACE booklets (e.g. a single
| number issued in several subjects), so they no longer need to be unique,
| not even per user.
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
        Schema::table('paces', function (Blueprint $table) {
            // MySQL won't drop an index backing a foreign key until another
            // index covers that column, so add a plain one first.
            $table->index('created_by');
            $table->dropUnique(['created_by', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('paces', function (Blueprint $table) {
            $table->unique(['created_by', 'number']);
            $table->dropIndex(['created_by']);
        });
    }
};
