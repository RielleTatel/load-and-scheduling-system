<?php

namespace Tests\Feature\Chair;

use App\Enums\SubmissionStatus;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlantillaUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_extracts_to_staging(): void
    {
        $this->seed();
        Storage::fake('local');
        $chair = User::where('email', 'chair.fil@jhs.test')->first();

        $pdf = new UploadedFile(
            base_path('tests/Fixtures/filipino-plantilla.pdf'),
            'plantilla.pdf', 'application/pdf', null, true,
        );

        $this->actingAs($chair)->post(route('chair.plantilla.store'), ['pdf' => $pdf])
            ->assertRedirect(route('chair.plantilla.review'));

        $upload = PlantillaUpload::first();
        $this->assertSame('extracted', $upload->extraction_status);
        $this->assertGreaterThanOrEqual(5, $upload->rows()->count());
    }

    public function test_upload_blocked_when_submitted(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        PlantillaSubmission::currentFor($chair->department_id)
            ->update(['status' => SubmissionStatus::Submitted]);

        $this->actingAs($chair)->post(route('chair.plantilla.store'), [
            'pdf' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }
}
