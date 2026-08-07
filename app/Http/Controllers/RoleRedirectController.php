<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class RoleRedirectController extends Controller
{
    /**
     * Send a freshly-authenticated user to the dashboard for their role.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        return match ($request->user()->role) {
            UserRole::SystemAdmin => redirect()->route('admin.dashboard'),
            UserRole::DepartmentChair => redirect()->route('chair.dashboard'),
            UserRole::AcademicCoordinator => abort(403, 'Coordinator features arrive in a later milestone.'),
        };
    }
}
