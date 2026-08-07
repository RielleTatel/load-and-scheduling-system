<?php

namespace Tests\Unit;

use App\Enums\EmploymentStatus;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Models\Teacher;
use App\Models\TeacherOtherAssignment;
use App\Models\User;
use App\Services\Plantilla\PlantillaReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlantillaReviewServiceTest extends TestCase
{
    use RefreshDatabase;

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
            $upload->rows()->create(['row_json' => $row, 'row_status' => 'extracted']);
        }

        return $upload;
    }

    public function test_confirm_import_creates_authoritative_records(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Leah Angelic C. Bilbar', 'employment_status' => 'Permanent',
            'sections' => 'G7: Ignatius', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => 'Department Chair', 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(1, $result['imported']);

        $teacher = Teacher::where('full_name', 'Leah Angelic C. Bilbar')->first();
        $this->assertNotNull($teacher);
        $this->assertSame(EmploymentStatus::Permanent, $teacher->employment_status);
        $this->assertSame(1, $teacher->sectionAssignments()->count());
        $this->assertEquals(4.0, (float) $teacher->sectionAssignments()->first()->hours); // FIL = 4h
        $this->assertDatabaseHas('service_loads', ['teacher_id' => $teacher->id, 'hours' => 3]);
        $this->assertSame(1, TeacherOtherAssignment::count());
        $this->assertSame('reviewed', $upload->fresh()->extraction_status);
    }

    public function test_unknown_status_is_skipped_and_reported(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Mystery Person', 'employment_status' => 'Freelance',
            'sections' => null, 'cm' => null, 'hc' => null, 'service_load' => null,
            'other_assignment' => null, 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['skipped']);
        $this->assertNull(Teacher::where('full_name', 'Mystery Person')->first());
    }

    public function test_nameless_rows_are_skipped(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => null, 'employment_status' => 'Permanent',
            'sections' => 'G7: Ignatius', 'flagged' => true,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, Teacher::count());
    }
}
