<?php

namespace App\Enums;

enum Quarter: int
{
    case First = 1;
    case Second = 2;
    case Third = 3;
    case Fourth = 4;

    /**
     * Human readable label, e.g. "1st Quarter".
     */
    public function label(): string
    {
        return match ($this) {
            self::First => '1st Quarter',
            self::Second => '2nd Quarter',
            self::Third => '3rd Quarter',
            self::Fourth => '4th Quarter',
        };
    }

    /**
     * All backing values.
     *
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }
}
