<?php

namespace Database\Seeders\Concerns;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\PersonDetails;

/**
 * Finds the demo school's records again, so each demo seeder can run after the
 * one that created them (and on its own, once the school exists).
 */
trait DemoSchool
{
    public const DEMO_SCHOOL = 'Grace Christian Academy';

    /** The school year everyone works in. */
    public const DEMO_YEAR = '2026-2027';

    /** Registered, with its grade levels and sections but no students yet: where the year-end promotion goes. */
    public const DEMO_NEXT_YEAR = '2027-2028';

    protected function school(): ?Organization
    {
        return Organization::query()->where('name', self::DEMO_SCHOOL)->first();
    }

    protected function requireSchool(): Organization
    {
        $school = $this->school();

        if ($school === null) {
            throw new \RuntimeException('The demo school does not exist yet: run DemoSchoolSeeder first.');
        }

        return $school;
    }

    protected function admin(): User
    {
        return User::query()->where('email', 'admin@example.com')->firstOrFail();
    }

    /** A demo teacher by first name (ana@example.com, ben@example.com, ...). */
    protected function teacher(string $firstName): User
    {
        return User::query()->where('email', strtolower($firstName).'@example.com')->firstOrFail();
    }

    protected function branch(string $name): Branch
    {
        return Branch::query()->where('organization_id', $this->requireSchool()->id)->where('name', $name)->firstOrFail();
    }

    protected function currentYear(): SchoolYear
    {
        return $this->year(self::DEMO_YEAR);
    }

    protected function nextYear(): SchoolYear
    {
        return $this->year(self::DEMO_NEXT_YEAR);
    }

    private function year(string $name): SchoolYear
    {
        return SchoolYear::query()
            ->where('organization_id', $this->requireSchool()->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * A demo account's name in parts (and the full name composed from them),
     * contact number, address, and, for accounts that signed up themselves, the
     * acceptance of the Terms of Service and Privacy Policy.
     *
     * @return array<string, mixed>
     */
    protected function person(string $first, ?string $middle, string $last, ?string $suffix = null, ?string $phone = null, ?string $address = null, bool $signedUp = true): array
    {
        return [
            'name' => PersonDetails::fullName($first, $middle, $last, $suffix),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'suffix' => $suffix,
            'phone' => $phone,
            'address' => $address,
            'terms_accepted_at' => $signedUp ? now() : null,
            'terms_version' => $signedUp ? config('saas.terms_version') : null,
        ];
    }
}
