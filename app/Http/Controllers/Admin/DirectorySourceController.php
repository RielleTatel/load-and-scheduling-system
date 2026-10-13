<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDirectorySourceRequest;
use App\Models\Department;
use App\Models\SystemConstant;
use App\Services\Teachers\TeacherReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Recording an official teacher directory when one arrives.
 *
 * The directory is optional evidence, not a prerequisite: teachers are
 * created from reviewed plantillas and verified without it. When it does
 * arrive, its names are matched like any other source.
 */
class DirectorySourceController extends Controller
{
    public function create(): View
    {
        return view('admin.directory-sources.create', [
            'departments' => Department::orderBy('name')->get(),
            'schoolYear' => SystemConstant::get('current_school_year'),
        ]);
    }

    public function store(StoreDirectorySourceRequest $request, TeacherReconciliationService $reconciliation): RedirectResponse
    {
        $counts = $reconciliation->recordDirectory(
            $request->validated('reference'),
            $request->validated('school_year'),
            $request->validated('completeness'),
            $request->validated('department_id') ? Department::find($request->validated('department_id')) : null,
            preg_split('/\r\n|\r|\n/', $request->validated('names')),
            $request->user(),
        );

        return redirect()->route('admin.teachers.index')->with('status',
            "Directory recorded: {$counts['linked']} linked, {$counts['review']} need a decision, "
            . "{$counts['unmatched']} not on file, {$counts['absences']} absence(s) to investigate.");
    }
}
