<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\SystemConstant;
use App\Models\User;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $schoolYear = SystemConstant::get('current_school_year');

        $submitted = PlantillaSubmission::where('school_year', $schoolYear)
            ->where('status', 'submitted')->count();

        return view('admin.dashboard', [
            'userCount' => User::count(),
            'departmentCount' => Department::count(),
            'submittedCount' => $submitted,
            'schoolYear' => $schoolYear,
        ]);
    }
}
