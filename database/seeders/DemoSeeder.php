<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A complete demo school for development and demos (never for production):
 *
 *   DemoSchoolSeeder       owner, school, branches, admin, teachers (name parts, contact numbers, terms accepted),
 *                          school years (last, this and next year), pending join requests (a teacher and an administrator)
 *   DemoGradeLevelsSeeder  grade levels, subjects, sections, offered subjects, teaching assignments, next year's empty classes
 *   DemoStudentsSeeder     students with the subjects they take, ID numbers, emergency contacts, enrollment history
 *   DemoPacesSeeder        PACE catalogs, pools, teacher stock, stock requests
 *   DemoRecordsSeeder      PACE records and student change requests
 *
 * Run it on a freshly migrated database: `php artisan db:seed` or
 * `php artisan db:seed --class=DemoSeeder`. Each part skips what already exists.
 * Every account's password is "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('The demo data is not seeded in production.');

            return;
        }

        // The same names, dates and scores every time.
        fake()->seed(2026);

        $this->call([
            DemoSchoolSeeder::class,
            DemoGradeLevelsSeeder::class,
            DemoStudentsSeeder::class,
            DemoPacesSeeder::class,
            DemoRecordsSeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->info('Sign in with (password: "password"):');
        $this->command?->line('  owner@example.com   platform owner (CEO)');
        $this->command?->line('  admin@example.com   school admin of '.DemoSchoolSeeder::DEMO_SCHOOL);
        $this->command?->line('  ana@, ben@, carla@, diego@ (Main Campus) and elena@, felix@ (North Campus) example.com   teachers');
        $this->command?->line('  gina@ (teacher) and hannah@ (administrator) example.com   not in a school yet, each with a pending join request');
        $this->command?->line('  sunrise.admin@ and tomas@example.com   the small second school on the free trial');
    }
}
