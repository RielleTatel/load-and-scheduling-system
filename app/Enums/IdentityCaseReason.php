<?php

namespace App\Enums;

/**
 * Why a written name could not be linked to a teacher without an Admin.
 */
enum IdentityCaseReason: string
{
    // A similar but unapproved spelling of an existing teacher.
    case PossibleMatch = 'possible_match';
    // More than one existing teacher carries the name.
    case AmbiguousMatch = 'ambiguous_match';
    // The name exists in another department.
    case DepartmentConflict = 'department_conflict';
    // A teacher is missing from an applicable complete directory.
    case DirectoryAbsence = 'directory_absence';

    public function label(): string
    {
        return match ($this) {
            self::PossibleMatch => 'Possible spelling variant',
            self::AmbiguousMatch => 'More than one possible teacher',
            self::DepartmentConflict => 'Department disagreement',
            self::DirectoryAbsence => 'Missing from a complete directory',
        };
    }
}
