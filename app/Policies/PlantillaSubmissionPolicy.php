<?php

namespace App\Policies;

use App\Models\PlantillaSubmission;
use App\Models\User;

class PlantillaSubmissionPolicy
{
    /**
     * A chair may view their own department's submission.
     */
    public function view(User $user, PlantillaSubmission $submission): bool
    {
        return $user->isChair() && $user->department_id === $submission->department_id;
    }

    /**
     * A chair may edit their own submission only while it's still open
     * (Draft or Returned) — a Submitted/Locked dataset is read-only.
     */
    public function update(User $user, PlantillaSubmission $submission): bool
    {
        return $user->isChair()
            && $user->department_id === $submission->department_id
            && $submission->status->isEditable();
    }
}
