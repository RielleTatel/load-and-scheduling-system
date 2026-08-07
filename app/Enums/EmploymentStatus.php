<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Permanent = 'permanent';
    case Probationary1 = 'probationary_1';
    case Probationary2 = 'probationary_2';
    case Probationary3 = 'probationary_3';
    case Substitute = 'substitute';
    case Retiree = 'retiree';

    /**
     * Canonicalize a raw plantilla label to the fixed enum (SRS FR-6).
     * Returns null when the label doesn't map to a known status.
     */
    public static function fromLabel(string $raw): ?self
    {
        $label = strtolower(trim($raw));

        if ($label === '') {
            return null;
        }
        if (str_contains($label, 'substitute')) {
            return self::Substitute;
        }
        if (str_contains($label, 'retiree')) {
            return self::Retiree;
        }
        if (str_contains($label, 'new teacher')) {
            return self::Probationary1;
        }
        if (preg_match('/probationary\s*([1-3])/', $label, $m)) {
            return self::from('probationary_' . $m[1]);
        }
        if (str_contains($label, 'permanent')) {
            return self::Permanent;
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Probationary1 => 'Probationary 1',
            self::Probationary2 => 'Probationary 2',
            self::Probationary3 => 'Probationary 3',
            self::Substitute => 'Substitute',
            self::Retiree => 'Retiree',
        };
    }
}
