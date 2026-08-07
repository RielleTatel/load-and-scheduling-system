<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Department;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserProvisioningService $provisioning) {}

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

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->provisioning->create($request->validated());

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user,
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->provisioning->update($user, $request->validated());

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $this->provisioning->setActive($user, ! $user->is_active);

        return redirect()->route('admin.users.index')
            ->with('status', $user->is_active ? 'User reactivated.' : 'User deactivated.');
    }
}
