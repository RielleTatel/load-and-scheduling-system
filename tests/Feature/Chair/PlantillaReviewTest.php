<?php

namespace Tests\Feature\Chair;

use App\Enums\ExtractionRowStatus;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlantillaReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a submission → upload → staging rows for the chair's department.
     */
    private function makeUploadFor(User $chair, array $rows): PlantillaUpload
    {
        $submission = PlantillaSubmission::currentFor($chair->department_id);
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => $submission->id,
            'file_path' => 'plantillas/test.pdf',
            'original_filename' => 'test.pdf',
            'extraction_status' => 'extracted',
        ]);

        foreach ($rows as $row) {
            $upload->rows()->create([
                'row_json' => array_merge([
                    'teacher_name' => null, 'employment_status' => null, 'sections' => null,
                    'cm' => null, 'hc' => null, 'service_load' => null, 'other_assignment' => null,
                    'flagged' => false,
                ], $row),
                'row_status' => ($row['flagged'] ?? false) ? ExtractionRowStatus::Flagged : ExtractionRowStatus::Extracted,
            ]);
        }

        return $upload;
    }

    public function test_review_screen_shows_rows(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->makeUploadFor($chair, [['teacher_name' => 'Leah Angelic C. Bilbar', 'flagged' => false]]);

        $this->actingAs($chair)->get(route('chair.plantilla.review'))
            ->assertOk()->assertSee('Leah Angelic C. Bilbar');
    }

    public function test_chair_edits_flagged_row(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $upload = $this->makeUploadFor($chair, [['teacher_name' => null, 'flagged' => true]]);
        $row = $upload->rows()->first();

        $this->actingAs($chair)->patch(route('chair.plantilla.rows.update', $row), [
            'teacher_name' => 'Leah Angelic C. Bilbar', 'employment_status' => 'Permanent',
            'sections' => 'G7: Ignatius',
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame('Leah Angelic C. Bilbar', $row->row_json['teacher_name']);
        $this->assertSame(ExtractionRowStatus::Extracted, $row->row_status);
    }

    public function test_other_chair_cannot_touch_row(): void
    {
        $this->seed();
        $fil = User::where('email', 'chair.fil@jhs.test')->first();
        $cle = User::where('email', 'chair.cle@jhs.test')->first();
        $row = $this->makeUploadFor($fil, [['teacher_name' => 'X', 'flagged' => false]])->rows()->first();

        $this->actingAs($cle)->patch(route('chair.plantilla.rows.update', $row),
            ['teacher_name' => 'Hacked'])->assertNotFound();
    }
}
