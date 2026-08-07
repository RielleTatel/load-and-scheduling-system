<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::with('department')
            ->when($request->q, fn ($query, $term) => $query->where(fn ($w) =>
                $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($request->role, fn ($query, $role) => $query->where('role', $role))
            ->when($request->department, fn ($query, $dept) => $query->where('department_id', $dept))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'departments' => Department::orderBy('name')->get(),
            'filters' => $request->only('q', 'role', 'department'),
        ]);
    }
}
