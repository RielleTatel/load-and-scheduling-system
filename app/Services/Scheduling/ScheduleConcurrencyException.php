<?php

namespace App\Services\Scheduling;

use DomainException;

class ScheduleConcurrencyException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This timetable changed in another session. Refresh it before making another edit.');
    }
}
