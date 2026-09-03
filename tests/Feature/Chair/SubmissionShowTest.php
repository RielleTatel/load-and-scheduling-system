<?php

namespace Tests\Feature\Chair;

use App\Enums\SubmissionStatus;
use App\Models\PlantillaSubmission;
use App\Models\SystemConstant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_submission_shows_the_coordinators_comment(): void
    {
        // SubmissionStatus::Returned isEditable(), so show() falls into the
        // normal editable branch. Without this, returned_comment is written to
        // the DB but never reaches the Chair — they see a plain "submit" screen
        // with no sign anything was returned.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $coordinator = User::factory()->create(['name' => 'Sam Alegado']);

        PlantillaSubmission::create([
            'department_id' => $chair->department_id,
            'school_year' => SystemConstant::get('current_school_year'),
            'status' => SubmissionStatus::Returned,
            'returned_comment' => 'Section counts for Comeros do not match the roster.',
            'returned_by_user_id' => $coordinator->id,
        ]);

        $this->actingAs($chair)->get(route('chair.submission.show'))
            ->assertOk()
            ->assertSee('Section counts for Comeros do not match the roster.')
            ->assertSee('Sam Alegado');
    }

    public function test_draft_submission_shows_no_returned_banner(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();

        $this->actingAs($chair)->get(route('chair.submission.show'))
            ->assertOk()
            ->assertDontSee('Returned by');
    }
}
