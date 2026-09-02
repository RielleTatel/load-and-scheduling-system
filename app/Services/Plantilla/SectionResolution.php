<?php

namespace App\Services\Plantilla;

use App\Models\Section;

final class SectionResolution
{
    public function __construct(
        public readonly string $raw,
        public readonly ?Section $section,
        public readonly SectionMatch $match,
        public readonly ?string $reason = null,
    ) {}

    public function isResolved(): bool
    {
        return $this->section !== null;
    }

    public function label(): string
    {
        return $this->section
            ? $this->section->grade_level->value . ': ' . $this->section->name
            : $this->raw;
    }
}
