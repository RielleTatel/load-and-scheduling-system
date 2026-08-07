<?php

namespace App\Services\Plantilla;

use App\Enums\SubmissionStatus;
use App\Models\PlantillaSubmission;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use DomainException;

class SubmissionService
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * Hand a department's dataset off for review: Draft/Returned → Submitted,
     * which locks all further chair editing (enforced by the policy).
     *
     * @throws DomainException if the dataset isn't in an editable state
     */
    public function submit(PlantillaSubmission $submission, User $by): PlantillaSubmission
    {
        if (! $submission->status->isEditable()) {
            throw new DomainException('Only a draft or returned dataset can be submitted.');
        }

        $submission->update([
            'status' => SubmissionStatus::Submitted,
            'submitted_by_user_id' => $by->id,
            'submitted_at' => now(),
        ]);
        $this->audit->log('plantilla.submitted', $submission, after: ['status' => 'submitted']);

        return $submission;
    }
}
