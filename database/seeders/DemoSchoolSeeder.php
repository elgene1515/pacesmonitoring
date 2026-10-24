<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrganizationService;
use App\Services\SchoolYearService;
use App\Services\SubscriptionService;
use Database\Seeders\Concerns\DemoSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The people and the school around them: the platform owner, a demo school on
 * the Growth plan with two branches, its admin and teachers (names in parts,
 * contact numbers, the Terms and Privacy Policy accepted), the school years
 * (last year, this year, and next year for the year-end promotion), two
 * pending join requests (a teacher and an administrator), and a second small
 * school for the owner's lists. Every account's password is "password".
 */
class DemoSchoolSeeder extends Seeder
{
    use DemoSchool;

    public function run(): void
    {
        if (! User::query()->where('email', 'owner@example.com')->exists()) {
            // The platform owner is made by an artisan command, not through sign-up: no terms acceptance recorded.
            User::factory()->owner()->create(
                $this->person('Olivia', null, 'Owner', signedUp: false) + ['email' => 'owner@example.com']
            );
        }

        if ($this->school() !== null) {
            $this->command?->info('The demo school already exists; leaving it as it is.');

            return;
        }

        $organizations = app(OrganizationService::class);

        $school = Organization::query()->create([
            'name' => self::DEMO_SCHOOL,
            'code' => Organization::generateCode(),
            'contact_email' => 'admin@example.com',
            'contact_phone' => '+63 917 555 0100',
        ]);

        // The branches are created directly (no sign-up limit check): the demo school is on Growth, which allows two.
        $main = $organizations->createBranch($school, ['name' => 'Main Campus', 'address' => '12 Hill Street, Quezon City', 'is_main' => true], false);
        $north = $organizations->createBranch($school, ['name' => 'North Campus', 'address' => '88 Rosal Avenue, Caloocan'], false);

        $admin = User::factory()->admin()->inOrganization($school, $main)->create(
            $this->person('Maria', 'Lopez', 'Santos', null, '+63 917 555 0100', '12 Hill Street, Quezon City')
            + ['email' => 'admin@example.com']
        );

        $school->forceFill(['created_by' => $admin->id])->save();

        $growth = Plan::query()->where('slug', 'growth')->firstOrFail();

        app(SubscriptionService::class)->assign($school, [
            'plan_id' => $growth->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->addYear()->toDateString(),
            'notes' => 'Demo school on the Growth plan.',
        ], $admin);

        // First name, middle name, surname, suffix, phone, address, branch.
        $teachers = [
            'ana' => [['Ana', null, 'Cruz', null, '0917 555 0111', '5 Acacia Street, Quezon City'], $main],
            'ben' => [['Ben', 'Antonio', 'Reyes', null, '0917 555 0112', '27 Narra Road, Marikina'], $main],
            'carla' => [['Carla', null, 'Mendoza', null, '0917 555 0113', null], $main],
            'diego' => [['Diego', 'Luis', 'Ramos', 'Jr.', '0917 555 0114', '9 Mabini Street, Pasig'], $main],
            'elena' => [['Elena', null, 'Torres', null, '0917 555 0115', null], $north],
            'felix' => [['Felix', null, 'Garcia', null, '0917 555 0116', '31 Rosal Avenue, Caloocan'], $north],
        ];

        foreach ($teachers as $handle => [$parts, $branch]) {
            User::factory()->inOrganization($school, $branch)->create($this->person(...$parts) + ['email' => $handle.'@example.com']);
        }

        // School years: last year for reference, this year is the default everyone works in,
        // and next year is registered (its grade levels come with DemoGradeLevelsSeeder) for the year-end promotion.
        $years = app(SchoolYearService::class);
        $years->create($admin, ['name' => '2025-2026', 'start_date' => '2025-06-02', 'end_date' => '2026-03-27']);
        $years->create($admin, ['name' => self::DEMO_YEAR, 'start_date' => '2026-06-01', 'end_date' => '2027-03-26', 'is_current' => true]);
        $years->create($admin, ['name' => self::DEMO_NEXT_YEAR, 'start_date' => '2027-06-07', 'end_date' => '2028-03-31']);

        // Two people waiting to be accepted (the admin's join request badge on Users): a teacher, and an
        // administrator who entered the school code to join as a second administrator.
        $gina = User::factory()->unassigned()->create(
            $this->person('Gina', null, 'Lopez', null, '0917 555 0120') + ['email' => 'gina@example.com']
        );

        JoinRequest::factory()->create([
            'user_id' => $gina->id,
            'organization_id' => $school->id,
            'branch_id' => $main->id,
            'message' => 'I teach Grade 3 and would like to join the main campus.',
        ]);

        $hannah = User::factory()->admin()->unassigned()->create(
            $this->person('Hannah', 'Cruz', 'Villanueva', null, '0917 555 0121', '14 Sampaguita Street, Quezon City') + ['email' => 'hannah@example.com']
        );

        JoinRequest::factory()->create([
            'user_id' => $hannah->id,
            'organization_id' => $school->id,
            'branch_id' => $main->id,
            'requested_role' => 'admin',
            'message' => 'The principal asked me to help run the school office.',
        ]);

        // A second, small school on the free trial so the owner's lists have more than one row.
        $sunrise = Organization::factory()->create(['name' => 'Sunrise Learning Center', 'contact_email' => 'sunrise.admin@example.com']);
        $sunriseAdmin = User::factory()->admin()->inOrganization($sunrise)->create(
            $this->person('Sofia', null, 'Dizon', null, '0917 555 0130') + ['email' => 'sunrise.admin@example.com']
        );
        $sunrise->forceFill(['created_by' => $sunriseAdmin->id])->save();
        User::factory()->inOrganization($sunrise)->create(
            $this->person('Tomas', null, 'Bautista', null, '0917 555 0131') + ['email' => 'tomas@example.com']
        );

        $this->command?->info('Demo school created: owner@example.com, admin@example.com and six teachers (password: "password").');
    }
}
