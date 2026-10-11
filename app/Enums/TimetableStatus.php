<?php

namespace App\Enums;

enum TimetableStatus: string
{
    case Generating = 'generating';
    case Draft = 'draft';
    case Approved = 'approved';
    case Superseded = 'superseded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Generating => 'Generating',
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Superseded => 'Superseded',
            self::Failed => 'Failed',
            self::Cancelled => 'Stopped',
        };
    }
}
