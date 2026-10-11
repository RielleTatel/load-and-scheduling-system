<?php

namespace App\Services\Scheduling;

use DomainException;

class ScheduleNotReadyException extends DomainException
{
    /** @param list<array<string, mixed>> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Schedule generation is blocked until readiness issues are resolved.');
    }
}
