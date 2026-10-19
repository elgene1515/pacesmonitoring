<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Every PACE keeps a stock count: the booklets the teacher has on hand.
| Issuing a PACE to a student takes one (un-issuing or deleting the record
| gives it back); the teacher adjusts the count by hand and the school admin
| tops it up when a stock request is fulfilled.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paces', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('paces', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};
