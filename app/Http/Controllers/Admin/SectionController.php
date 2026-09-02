<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSectionRequest;
use App\Models\Section;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The registrar's section roster. It is reference data for the whole school —
 * plantilla extraction recovers a section's grade from its name, and the
 * moderator/teacher-partner columns are the only complete record of those
 * assignments, since four of the seven sheets never state a moderator.
 */
class SectionController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    public function index(): View
    {
        return view('admin.sections.index', [
            // Sorted in PHP: grade order is G7..G10, which is neither
            // alphabetical nor portable as raw SQL across MySQL and SQLite.
            'sections' => Section::withCount('teacherAssignments')
                ->with('moderatorAssignment.teacher')
                ->orderBy('name')->get()
                ->groupBy(fn (Section $s) => $s->grade_level->value)
                ->sortBy(fn ($rows, $grade) => (int) substr($grade, 1)),
        ]);
    }

    public function create(): View
    {
        return view('admin.sections.form', ['section' => new Section()]);
    }

    public function store(StoreSectionRequest $request): RedirectResponse
    {
        $section = Section::create($request->validated());
        $this->audit->log('section.created', $section, after: $section->only('grade_level', 'name', 'room'));

        return redirect()->route('admin.sections.index')->with('status', "{$section->name} added to the roster.");
    }

    public function edit(Section $section): View
    {
        return view('admin.sections.form', ['section' => $section]);
    }

    public function update(StoreSectionRequest $request, Section $section): RedirectResponse
    {
        $before = $section->only('grade_level', 'name', 'room', 'moderator_name');
        $section->update($request->validated());
        $this->audit->log('section.updated', $section, before: $before, after: $section->only('grade_level', 'name', 'room', 'moderator_name'));

        return redirect()->route('admin.sections.index')->with('status', "{$section->name} updated.");
    }
}
