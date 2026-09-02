<?php

namespace App\Services\Plantilla;

enum SectionMatch: string
{
    case Exact = 'exact';
    case Alias = 'alias';
    case Fuzzy = 'fuzzy';
    case Unresolved = 'unresolved';

    /** Fuzzy matches import, but the Chair should eyeball them. */
    public function needsReview(): bool
    {
        return $this === self::Fuzzy || $this === self::Unresolved;
    }
}
