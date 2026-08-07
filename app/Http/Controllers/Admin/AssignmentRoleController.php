<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAssignmentRoleRequest;
use App\Models\OtherAssignmentRole;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AssignmentRoleController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => OtherAssignmentRole::orderBy('is_honorarium')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', ['role' => new OtherAssignmentRole()]);
    }

    public function store(StoreAssignmentRoleRequest $request): RedirectResponse
    {
        $role = OtherAssignmentRole::create($this->payload($request->validated()));
        $this->audit->log('assignment_role.created', $role, after: $role->only('name', 'equivalent_hours', 'is_honorarium'));

        return redirect()->route('admin.roles.index')->with('status', 'Assignment role added.');
    }

    public function edit(OtherAssignmentRole $role): View
    {
        return view('admin.roles.form', ['role' => $role]);
    }

    public function update(StoreAssignmentRoleRequest $request, OtherAssignmentRole $role): RedirectResponse
    {
        $before = $role->only('name', 'equivalent_hours', 'is_honorarium');
        $role->update($this->payload($request->validated()));
        $this->audit->log('assignment_role.updated', $role, $before, $role->only('name', 'equivalent_hours', 'is_honorarium'));

        return redirect()->route('admin.roles.index')->with('status', 'Assignment role updated.');
    }

    /**
     * Honorarium roles always store null hours regardless of any submitted value.
     */
    private function payload(array $data): array
    {
        return [
            'name' => $data['name'],
            'is_honorarium' => $data['is_honorarium'],
            'equivalent_hours' => $data['is_honorarium'] ? null : ($data['equivalent_hours'] ?? null),
        ];
    }
}
