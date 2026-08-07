<x-app-layout>
    <x-page-header eyebrow="System Administration · {{ $schoolYear }}" title="Dashboard" />

    <div class="grid gap-5 sm:grid-cols-3 mb-6">
        <x-stat-tile label="Accounts" :value="$userCount" note="Admins and department chairs" />
        <x-stat-tile label="Departments" :value="$departmentCount" tone="navy" note="Fixed JHS departments" />
        <x-stat-tile label="Submitted" :value="$submittedCount . ' / ' . $departmentCount" tone="amber" note="Plantillas handed off for review" />
    </div>

    <div class="card p-6">
        <h2 class="font-bold text-base mb-3">Quick actions</h2>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.users.index') }}" class="btn-primary">Manage users</a>
            @if (Route::has('admin.constants.index'))
                <a href="{{ route('admin.constants.index') }}" class="btn-secondary">System constants</a>
            @endif
            @if (Route::has('admin.roles.index'))
                <a href="{{ route('admin.roles.index') }}" class="btn-secondary">Assignment roles</a>
            @endif
            @if (Route::has('admin.audit.index'))
                <a href="{{ route('admin.audit.index') }}" class="btn-ghost">Audit log</a>
            @endif
        </div>
    </div>
</x-app-layout>
