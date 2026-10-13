<?php

namespace App\Services\Teachers;

use App\Models\Teacher;

/**
 * One source naming one person, as the identity service sees it.
 *
 * `sourceKey` identifies the row and field ("plantilla_row:12",
 * "roster_row:7:moderator") so a retried confirmation finds its earlier
 * mapping. `departmentId` is what the source says; null when the source
 * (the registrar) does not know.
 */
final class IdentityObservation
{
    public const PLANTILLA = 'plantilla';

    public const REGISTRAR = 'registrar';

    public const DIRECTORY = 'directory';

    public const MANUAL = 'manual';

    public readonly string $name;

    public readonly string $normalizedName;

    public function __construct(
        public readonly string $sourceType,
        public readonly ?string $sourceKey,
        string $name,
        public readonly ?int $departmentId,
        public readonly ?string $sourceReference = null,
        public readonly ?string $schoolYear = null,
        public readonly ?string $rawName = null,
        public readonly ?string $completeness = null,
        public readonly ?int $coverageDepartmentId = null,
    ) {
        $this->name = trim($name);
        $this->normalizedName = Teacher::normalize($this->name);
    }

    /** Changes when an identity-bearing value (name or department) is edited. */
    public function identityHash(): string
    {
        return hash('sha256', $this->normalizedName.'|'.($this->departmentId ?? ''));
    }
}
