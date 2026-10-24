<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The plans come from the migrations, and a real school sets itself up
     * from the app (the owner account is made with `php artisan
     * user:make-owner`). Everywhere but production this also seeds a demo
     * school to work with, see DemoSeeder.
     *
     * @return void
     */
    public function run()
    {
        if (app()->environment('production')) {
            return;
        }

        $this->call(DemoSeeder::class);
    }
}
