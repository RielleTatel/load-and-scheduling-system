<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Enums\ExtractionRowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chair\StorePlantillaUploadRequest;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Services\Plantilla\ExtractionFailedException;
use App\Services\Plantilla\PdfExtractionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PlantillaUploadController extends Controller
{
    public function __construct(private PdfExtractionService $extractor) {}

    public function create(): View
    {
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);

        return view('chair.plantilla.upload', ['submission' => $submission]);
    }

    public function store(StorePlantillaUploadRequest $request): RedirectResponse
    {
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);
        $this->authorize('update', $submission);

        $department = auth()->user()->department;
        $path = $request->file('pdf')->store("plantillas/{$department->code}", 'local');

        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => $submission->id,
            'file_path' => $path,
            'original_filename' => $request->file('pdf')->getClientOriginalName(),
            'extraction_status' => 'pending',
        ]);

        try {
            $rows = $this->extractor->extract($request->file('pdf')->getRealPath());

            foreach ($rows as $row) {
                $upload->rows()->create([
                    'row_json' => $row,
                    'row_status' => $row['flagged'] ? ExtractionRowStatus::Flagged : ExtractionRowStatus::Extracted,
                ]);
            }

            $upload->update(['extraction_status' => 'extracted', 'extracted_at' => now()]);

            return redirect()->route('chair.plantilla.review')
                ->with('status', count($rows) . ' rows extracted. Review and correct them before importing.');
        } catch (ExtractionFailedException $e) {
            $upload->update(['extraction_status' => 'failed']);

            return redirect()->route('chair.plantilla.review')
                ->with('warning', "Couldn't read that PDF automatically. Add your teachers manually below.");
        }
    }
}
