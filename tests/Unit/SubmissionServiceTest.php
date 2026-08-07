<?php

namespace Tests\Unit;

use App\Enums\SubmissionStatus;
use App\Models\PlantillaSubmission;
use App\Models\User;
use App\Services\Plantilla\SubmissionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transitions_and_audits(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);
        $sub = PlantillaSubmission::currentFor($chair->department_id);

        $out = app(SubmissionService::class)->submit($sub, $chair);

        $this->assertSame(SubmissionStatus::Submitted, $out->status);
        $this->assertNotNull($out->submitted_at);
        $this->assertSame($chair->id, $out->submitted_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plantilla.submitted']);

        $this->expectException(DomainException::class);
        app(SubmissionService::class)->submit($out, $chair); // already submitted
    }
}
