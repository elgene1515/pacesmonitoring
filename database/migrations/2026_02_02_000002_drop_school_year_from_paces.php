<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A PACE is not tied to a particular school year (it can be issued to
| different students across years), so it no longer carries a school year
| of its own.
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
            $table->dropConstrainedForeignId('school_year_id');
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
            $table->foreignId('school_year_id')->nullable()->after('number')->constrained()->nullOnDelete();
        });
    }
};
