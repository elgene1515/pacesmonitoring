<?php

namespace App\Enums;

/**
 * Lifecycle of a teacher's proposed edit to a student's details.
 *
 *  - pending:   waiting for the school admin
 *  - accepted:  the admin applied the change to the student
 *  - declined:  the admin turned it down
 *  - cancelled: the teacher withdrew it
 */
enum StudentChangeRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
