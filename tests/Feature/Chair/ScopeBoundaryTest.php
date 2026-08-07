<?php

namespace Tests\Feature\Chair;

use App\Enums\SubmissionStatus;
use App\Models\PlantillaSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_allows_own_draft_only(): void
    {
        $this->seed();
        $fil = User::where('email', 'chair.fil@jhs.test')->first();
        $cle = User::where('email', 'chair.cle@jhs.test')->first();
        $sub = PlantillaSubmission::currentFor($fil->department_id);

        $this->assertTrue($fil->can('update', $sub));
        $this->assertFalse($cle->can('update', $sub));

        $sub->update(['status' => SubmissionStatus::Submitted]);
        $this->assertFalse($fil->fresh()->can('update', $sub->fresh()));

        $sub->update(['status' => SubmissionStatus::Returned]);
        $this->assertTrue($fil->fresh()->can('update', $sub->fresh()));
    }
}
