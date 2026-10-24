<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The PACE catalog is shared per branch: one row for "1001 – Math" that
| every teacher of the branch and the admin refer to, instead of a private
| copy per teacher. Numbers repeat across subjects (Math 1001 and English
| 1001 are different booklets), so a catalog entry is unique per branch by
| number + name.
|
| `stock` (one teacher's booklets) becomes `pool_stock`: the branch's pool,
| what the admin has on hand. Teachers' booklets move to pace_stock_holdings.
| Stock requests remember the branch they were filed in, so approving one
| that cites a new number can register it in the right catalog.
|
| Written for a fresh database (see the grade levels migration).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paces', function (Blueprint $table) {
            $table->foreignId('branch_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pool_stock')->default(0)->after('number');
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->dropColumn('stock');
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->unique(['branch_id', 'number', 'name']);
        });

        Schema::table('pace_requests', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pace_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'number', 'name']);
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->after('number');
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->dropColumn('pool_stock');
        });

        Schema::table('paces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
