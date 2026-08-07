<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Enums\ExtractionRowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chair\UpdateExtractionRowRequest;
use App\Models\PlantillaExtractionRow;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Services\Plantilla\PlantillaReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PlantillaReviewController extends Controller
{
    public function show(): View
    {
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);
        $upload = $this->latestUpload($submission);

        return view('chair.plantilla.review', [
            'submission' => $submission,
            'upload' => $upload,
            'rows' => $upload?->rows()->orderBy('id')->get() ?? collect(),
            'blankRow' => $this->blankRow(),
        ]);
    }

    public function storeRow(UpdateExtractionRowRequest $request): RedirectResponse
    {
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);
        $this->authorize('update', $submission);

        $upload = $this->latestUpload($submission) ?? PlantillaUpload::create([
            'plantilla_submission_id' => $submission->id,
            'file_path' => 'manual',
            'original_filename' => 'manual entry',
            'extraction_status' => 'reviewed',
        ]);

        $upload->rows()->create([
            'row_json' => array_merge($this->blankRow(), $request->rowData()),
            'row_status' => ExtractionRowStatus::Extracted,
        ]);

        return back()->with('status', 'Row added.');
    }

    public function updateRow(UpdateExtractionRowRequest $request, PlantillaExtractionRow $row): RedirectResponse
    {
        $this->authorizeRow($row);

        $row->update([
            'row_json' => array_merge($this->blankRow(), $request->rowData()),
            'row_status' => ExtractionRowStatus::Extracted,
        ]);

        return back()->with('status', 'Row updated.');
    }

    public function destroyRow(PlantillaExtractionRow $row): RedirectResponse
    {
        $this->authorizeRow($row);
        $row->delete();

        return back()->with('status', 'Row removed.');
    }

    /**
     * 404 unless the row belongs to the requesting chair's department,
     * and the submission is still editable.
     */
    private function authorizeRow(PlantillaExtractionRow $row): void
    {
        $submission = $row->upload->submission;
        abort_unless($submission->department_id === auth()->user()->department_id, 404);
        $this->authorize('update', $submission);
    }

    private function latestUpload(PlantillaSubmission $submission): ?PlantillaUpload
    {
        return PlantillaUpload::where('plantilla_submission_id', $submission->id)
            ->latest('id')->first();
    }

    private function blankRow(): array
    {
        return [
            'teacher_name' => null, 'employment_status' => null, 'sections' => null,
            'cm' => null, 'hc' => null, 'service_load' => null, 'other_assignment' => null,
            'flagged' => false,
        ];
    }
}
