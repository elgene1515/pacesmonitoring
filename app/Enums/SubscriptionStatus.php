<?php

namespace App\Enums;

/**
 * Lifecycle of an organization's subscription.
 *
 *  trial / active   full access
 *  past_due         full access, but the UI warns that payment is overdue (grace period)
 *  expired          read-only: records can be viewed but nothing can be added or changed
 *  cancelled        read-only
 *
 * `expired` is also derived automatically when an active/trial subscription
 * runs past its end date, so nothing depends on a scheduler.
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Statuses under which the organization may still create and edit records.
     */
    public function allowsWrites(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::PastDue], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
