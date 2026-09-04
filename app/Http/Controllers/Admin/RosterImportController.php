<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExtractionRowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRosterImportRequest;
use App\Http\Requests\Admin\UpdateRosterRowRequest;
use App\Models\RosterExtractionRow;
use App\Models\RosterImport;
use App\Models\SystemConstant;
use App\Services\Plantilla\ExtractionFailedException;
use App\Services\Roster\RosterExtractionService;
use App\Services\Roster\RosterReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Ingests the registrar's "List of Class Moderators" — the roster that defines
 * every section a plantilla is later matched against. Extraction stages rows;
 * nothing reaches the sections table until the Admin confirms.
 */
class RosterImportController extends Controller
{
    public function __construct(private RosterExtractionService $extractor) {}

    public function create(): View
    {
        return view('admin.roster.upload');
    }

    public function store(StoreRosterImportRequest $request): RedirectResponse
    {
        $schoolYear = SystemConstant::get('current_school_year');
        $path = $request->file('pdf')->store('rosters', 'local');

        $import = RosterImport::create([
            'school_year' => $schoolYear,
            'file_path' => $path,
            'original_filename' => $request->file('pdf')->getClientOriginalName(),
            'extraction_status' => 'pending',
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        try {
            foreach ($this->extractor->extract($request->file('pdf')->getRealPath()) as $row) {
                $import->rows()->create([
                    'row_json' => $row,
                    'row_status' => $row['flagged'] ? ExtractionRowStatus::Flagged : ExtractionRowStatus::Extracted,
                ]);
            }

            $import->update(['extraction_status' => 'extracted', 'extracted_at' => now()]);

            return redirect()->route('admin.roster.review')
                ->with('status', $import->rows()->count() . ' sections extracted. Review them before importing.');
        } catch (ExtractionFailedException $e) {
            $import->update(['extraction_status' => 'failed']);

            return redirect()->route('admin.roster.review')
                ->with('warning', "Couldn't read that PDF automatically. Enter the roster manually instead.");
        }
    }

    public function review(): View
    {
        $import = RosterImport::latest('id')->first();

        return view('admin.roster.review', [
            'import' => $import,
            'rows' => $import?->rows()->orderBy('id')->get() ?? collect(),
        ]);
    }

    public function updateRow(UpdateRosterRowRequest $request, RosterExtractionRow $row): RedirectResponse
    {
        $row->update([
            'row_json' => $request->rowData(),
            'row_status' => ExtractionRowStatus::Extracted,
        ]);

        return back()->with('status', 'Row updated.');
    }

    public function confirm(RosterReviewService $rosters): RedirectResponse
    {
        $import = RosterImport::latest('id')->firstOrFail();
        $result = $rosters->confirmImport($import);

        if ($result['errors']) {
            return back()->with('warning', implode(' ', $result['errors']));
        }

        return redirect()->route('admin.sections.index')
            ->with('status', "Imported {$result['imported']} sections for {$import->school_year}.");
    }
}
