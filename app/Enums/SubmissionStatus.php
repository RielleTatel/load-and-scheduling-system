<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Locked = 'locked';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Chair may edit only while the dataset is not yet handed off. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }
}
