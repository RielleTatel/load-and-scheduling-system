<?php

namespace App\Enums;

enum TimetableKind: string
{
    case Normal = 'normal';
    case Special = 'special';

    public function label(): string
    {
        return $this === self::Special ? 'Special' : 'Normal';
    }

    public function periodsPerDay(): int
    {
        return $this === self::Special ? 8 : 7;
    }
}
