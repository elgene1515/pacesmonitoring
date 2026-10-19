<?php

namespace App\Enums;

/**
 * Lifecycle of a teacher's PACE stock request.
 *
 *  - pending:   waiting for the school admin
 *  - given:     the admin handed the booklets over (the linked PACE's stock was topped up)
 *  - declined:  the admin turned it down
 *  - cancelled: the teacher withdrew it
 */
enum PaceRequestStatus: string
{
    case Pending = 'pending';
    case Given = 'given';
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
