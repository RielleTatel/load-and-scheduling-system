<?php

namespace App\Services\Plantilla;

use App\Models\Teacher;

final class TeacherResolution
{
    public function __construct(
        public readonly Teacher $teacher,
        public readonly bool $created,
        public readonly ?string $reason = null,
    ) {}
}
