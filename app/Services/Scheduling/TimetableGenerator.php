<?php

namespace App\Services\Scheduling;

interface TimetableGenerator
{
    public function generate(array $sections, array $unavailable, int $seed, int $seconds = 110, ?\Closure $progress = null, array $startingEntries = [], ?\Closure $checkpoint = null, array $fallbackEntries = []): array;
}
