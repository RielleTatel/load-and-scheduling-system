<?php

namespace App\Enums;

/**
 * The Admin's outcome for an identity case.
 */
enum IdentityDecision: string
{
    // The observed name is this existing teacher; approve it as an alias.
    case Same = 'same';
    // The observed name is a different person; a new record may be created.
    case Distinct = 'distinct';
    // The existing teacher's department stands; the source was wrong.
    case KeepDepartment = 'keep_department';
    // The observed department is correct; move the teacher.
    case UseObservedDepartment = 'use_observed_department';
    // A directory absence was investigated and closed.
    case AbsenceExplained = 'absence_explained';
    // The staged name was edited or the batch removed; the question is moot.
    case Superseded = 'superseded';
}
