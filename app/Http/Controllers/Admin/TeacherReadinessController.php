<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemConstant;
use App\Services\Teachers\TeacherIdentityReadinessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TeacherReadinessController extends Controller
{
    public function index(Request $request, TeacherIdentityReadinessService $readiness): View
    {
        $schoolYear = (string) $request->query('school_year', SystemConstant::get('current_school_year'));
        abort_unless(preg_match('/^\d{4}-\d{4}$/', $schoolYear), 404);

        return view('admin.teachers.readiness', ['report' => $readiness->forSchoolYear($schoolYear)]);
    }
}
