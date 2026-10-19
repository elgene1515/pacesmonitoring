<?php

namespace App\Enums;

/**
 * Account roles. One user has exactly one role.
 *
 *  - owner:      the platform owner (you). Sees every organization, manages
 *                plans, subscriptions and the platform staff. Created from the
 *                console, never by sign-up.
 *  - vice_admin: platform staff appointed by the owner. Same view as the
 *                owner, except managing staff, deleting organizations and
 *                deleting plans.
 *  - admin:      a school administrator. Registers an organization and its
 *                branches, admits teachers, manages school years.
 *  - teacher:    belongs to one organization/branch and owns their own records.
 */
enum Role: string
{
    case Owner = 'owner';
    case ViceAdmin = 'vice_admin';
    case Admin = 'admin';
    case Teacher = 'teacher';

    /**
     * Roles a visitor may pick when signing up.
     *
     * @return array<int, string>
     */
    public static function registrable(): array
    {
        return [self::Admin->value, self::Teacher->value];
    }

    /**
     * Platform-side roles (not members of any organization).
     *
     * @return array<int, string>
     */
    public static function platform(): array
    {
        return [self::Owner->value, self::ViceAdmin->value];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    public function isPlatform(): bool
    {
        return in_array($this->value, self::platform(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'CEO',
            self::ViceAdmin => 'Admin',
            self::Admin => 'School admin',
            self::Teacher => 'Teacher',
        };
    }
}
