<?php

namespace App\Enums;

/**
 * Whether an Admin has attested to a teacher's canonical name and department.
 *
 * Pending is the starting state for every record, whatever created it; a
 * source label is provenance, never approval. Needs review means a later
 * source contradicted a verified identity and an Admin decision is open.
 */
enum TeacherVerification: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::NeedsReview => 'Needs review',
        };
    }
}
