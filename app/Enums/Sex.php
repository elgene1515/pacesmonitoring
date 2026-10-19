<?php

namespace App\Enums;

enum Sex: string
{
    case Male = 'male';
    case Female = 'female';

    /**
     * Human readable label for the case.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * All backing values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
