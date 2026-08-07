<x-app-layout>
    <x-page-header eyebrow="System Administration" title="Assignment Roles">
        <x-slot:actions>
            <a href="{{ route('admin.roles.create') }}" class="btn-primary">Add role</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <p class="text-slate-brand text-sm mb-5 max-w-2xl">
        The lookup of Other Assignment roles and their equivalent hours. Honorarium-only roles are compensated separately and never count toward load or overload.
    </p>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[560px]">
                <thead>
                    <tr class="bg-mist border-b border-line">
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Role</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Equivalent hours</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roles as $role)
                        <tr class="border-b border-line last:border-0 hover:bg-mist/60">
                            <td class="px-4 py-3.5 font-semibold text-[14px]">{{ $role->name }}</td>
                            <td class="px-4 py-3.5 text-right font-data">
                                @if ($role->is_honorarium)
                                    <span class="flag flag-warn">Honorarium</span>
                                @else
                                    {{ rtrim(rtrim(number_format($role->equivalent_hours, 1), '0'), '.') }}
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <a href="{{ route('admin.roles.edit', $role) }}" class="text-electric text-[13.5px] font-semibold hover:underline">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
