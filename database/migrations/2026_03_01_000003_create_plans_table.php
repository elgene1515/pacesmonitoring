<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Subscription plans, managed by the owner. The three tiers from the package
| deal are inserted so a fresh `php artisan migrate` leaves the app usable.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('max_branches')->default(1);
            $table->unsignedSmallInteger('max_teachers')->nullable();
            $table->decimal('price_per_year', 10, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('plans')->insert([
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'One school branch.',
                'max_branches' => 1,
                'max_teachers' => null,
                'price_per_year' => 15000,
                'currency' => 'PHP',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Growth',
                'slug' => 'growth',
                'description' => 'Up to two branches.',
                'max_branches' => 2,
                'max_teachers' => null,
                'price_per_year' => 25000,
                'currency' => 'PHP',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Multi-campus',
                'slug' => 'multi-campus',
                'description' => 'Up to five branches.',
                'max_branches' => 5,
                'max_teachers' => null,
                'price_per_year' => 50000,
                'currency' => 'PHP',
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
