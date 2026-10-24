<?php

namespace App\Enums;

/**
 * How booklets moved. Only the branch's pool ever gains stock from outside.
 *
 *  - received:    a delivery was added to the pool
 *  - distributed: the admin handed booklets from the pool to a teacher
 *  - returned:    booklets went back from a teacher to the pool
 *  - adjusted:    the admin corrected the pool count (signed quantity)
 */
enum PaceStockMovementType: string
{
    case Received = 'received';
    case Distributed = 'distributed';
    case Returned = 'returned';
    case Adjusted = 'adjusted';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
