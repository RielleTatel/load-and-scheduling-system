<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('chair.dashboard');
    }
}
