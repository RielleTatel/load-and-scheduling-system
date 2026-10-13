<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Services\Teachers\TeacherIdentityService;
use App\Services\Teachers\TeacherMergeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Previewed, all-or-nothing merging of a duplicate teacher record.
 */
class TeacherMergeController extends Controller
{
    public function __construct(private TeacherMergeService $merges) {}

    public function preview(Request $request, Teacher $teacher, TeacherIdentityService $identities): View
    {
        $survivor = $request->integer('survivor') ? Teacher::active()->find($request->integer('survivor')) : null;

        return view('admin.teachers.merge', [
            'teacher' => $teacher->load('department'),
            'survivor' => $survivor?->load('department'),
            'preview' => $survivor ? $this->merges->preview($teacher, $survivor) : null,
            'options' => Teacher::active()->whereKeyNot($teacher->id)->with('department')->orderBy('full_name')->get(),
            'suggested' => $identities->similarCandidates($teacher->full_name, $teacher->id)->pluck('id')->all(),
        ]);
    }

    public function merge(Request $request, Teacher $teacher): RedirectResponse
    {
        $data = $request->validate([
            'survivor_id' => ['required', 'integer', 'exists:teachers,id'],
            'token' => ['required', 'string'],
            'service' => ['array'],
            'service.*' => ['in:survivor,redundant'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $survivor = $this->merges->merge(
            $teacher, Teacher::findOrFail($data['survivor_id']), $data['token'], $data['service'] ?? [], $data['note'], $request->user(),
        );

        return redirect()->route('admin.teachers.edit', $survivor)
            ->with('status', "{$teacher->full_name} was merged into {$survivor->full_name}.");
    }
}
