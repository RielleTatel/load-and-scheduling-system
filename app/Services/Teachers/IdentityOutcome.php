<?php

namespace App\Services\Teachers;

use App\Enums\IdentityCaseReason;
use App\Models\Teacher;
use App\Models\TeacherIdentityCase;

/**
 * What the identity service concluded about one observation: an existing
 * teacher, a genuinely new person, or a question only an Admin can answer.
 */
final class IdentityOutcome
{
    public const EXISTING = 'existing';

    public const NEW = 'new';

    public const REVIEW = 'review';

    /** @param  list<int>  $candidateIds */
    private function __construct(
        public readonly string $kind,
        public readonly ?Teacher $teacher = null,
        public readonly ?IdentityCaseReason $reason = null,
        public readonly array $candidateIds = [],
        public readonly bool $distinctApproved = false,
        public ?TeacherIdentityCase $case = null,
    ) {}

    public static function existing(Teacher $teacher): self
    {
        return new self(self::EXISTING, $teacher);
    }

    public static function new(bool $distinctApproved = false): self
    {
        return new self(self::NEW, distinctApproved: $distinctApproved);
    }

    /** @param  list<int>  $candidateIds */
    public static function review(IdentityCaseReason $reason, array $candidateIds, ?TeacherIdentityCase $case = null): self
    {
        sort($candidateIds);

        return new self(self::REVIEW, reason: $reason, candidateIds: array_values(array_unique($candidateIds)), case: $case);
    }

    public function needsReview(): bool
    {
        return $this->kind === self::REVIEW;
    }

    /** Two outcomes lead to the same commit. */
    public function sameAs(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->teacher?->id === $other->teacher?->id
            && $this->candidateIds === $other->candidateIds;
    }
}
