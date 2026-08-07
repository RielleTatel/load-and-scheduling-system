<x-app-layout>
    <x-page-header eyebrow="System Administration" title="Users">
        <x-slot:actions>
            <a href="{{ route('admin.users.create') }}" class="btn-primary">Add user</a>
        </x-slot:actions>
    </x-page-header>

    {{-- Filters --}}
    <form method="GET" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
            <label for="q" class="field-label">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email" class="field-input">
        </div>
        <div class="min-w-[170px]">
            <label for="role" class="field-label">Role</label>
            <select id="role" name="role" class="field-input">
                <option value="">All roles</option>
                @foreach (\App\Enums\UserRole::cases() as $role)
                    <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[170px]">
            <label for="department" class="field-label">Department</label>
            <select id="department" name="department" class="field-input">
                <option value="">All departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}" @selected(($filters['department'] ?? '') == $dept->id)>{{ $dept->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary">Filter</button>
            <a href="{{ route('admin.users.index') }}" class="btn-ghost">Reset</a>
        </div>
    </form>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[720px]">
                <thead>
                    <tr class="bg-mist border-b border-line">
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Name</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Email</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Role</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Department</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr class="border-b border-line last:border-0 hover:bg-mist/60">
                            <td class="px-4 py-3.5 font-semibold text-[14px]">{{ $user->name }}</td>
                            <td class="px-4 py-3.5 font-data text-[13.5px]">{{ $user->email }}</td>
                            <td class="px-4 py-3.5 text-[13.5px]">{{ $user->role->label() }}</td>
                            <td class="px-4 py-3.5 text-[13.5px] text-slate-brand">{{ $user->department?->name ?? '—' }}</td>
                            <td class="px-4 py-3.5">
                                @if ($user->is_active)
                                    <span class="pill-submitted">Active</span>
                                @else
                                    <span class="pill-draft">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <a href="{{ route('admin.users.edit', $user) }}" class="text-electric text-[13.5px] font-semibold hover:underline">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-brand">No accounts match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</x-app-layout>
