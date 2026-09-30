<?php

namespace App\Services\Scheduling;

use RuntimeException;

final class ScheduleSearchExhaustedException extends RuntimeException
{
    /**
     * @param list<array<string, int|string>> $bestEntries
     * @param list<array<string, mixed>> $remainingIssues
     */
    public function __construct(
        string $message,
        public readonly array $bestEntries = [],
        public readonly array $remainingIssues = [],
    ) {
        parent::__construct($message);
    }
}
