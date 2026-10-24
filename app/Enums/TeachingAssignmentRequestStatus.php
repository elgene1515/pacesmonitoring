<?php

namespace App\Enums;

/**
 * Lifecycle of a teacher's request to teach a subject to a section.
 *
 *  - pending:   waiting for the school admin
 *  - accepted:  the admin assigned the slot to the teacher
 *  - declined:  the admin turned it down
 *  - cancelled: the teacher withdrew it
 */
enum TeachingAssignmentRequestStatus: string
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
