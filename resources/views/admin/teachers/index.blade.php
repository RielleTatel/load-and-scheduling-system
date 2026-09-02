<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Teacher Directory">
        <x-slot:actions>
            <a href="{{ route('admin.teachers.create') }}" class="btn-primary">Add teacher</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="flex flex-wrap gap-x-10 gap-y-4 mb-5">
        <div>
            <span class="stat-mini-number">{{ $teachers->count() }}</span>
            <span class="block text-slate-brand text-[12.5px]">In the directory</span>
        </div>
        <div>
            <span class="stat-mini-number">{{ $teachers->whereNotNull('department_id')->count() }}</span>
            <span class="block text-slate-brand text-[12.5px]">Assigned to a department</span>
        </div>
        <div>
            <span class="stat-mini-number {{ $unclaimed->count() ? 'text-amber-brand' : '' }}">{{ $unclaimed->count() }}</span>
            <span class="block text-slate-brand text-[12.5px]">Awaiting a plantilla</span>
        </div>
    </div>

    <p class="text-slate-brand text-sm mb-5 max-w-3xl">
        Every teacher in the school. Chairs only ever see their own department, so this is the one place the full
        staff list exists &mdash; and it is what plantilla imports are matched against, so a re-uploaded sheet with a
        corrected spelling updates the teacher instead of creating a second one.
    </p>

    @if ($unclaimed->isNotEmpty())
        <div class="card p-5 mb-5 border-l-4 border-amber-brand">
            <p class="font-bold text-[14px] mb-1">{{ $unclaimed->count() }} named by the registrar with no plantilla yet</p>
            <p class="text-slate-brand text-[13.5px]">
                These people moderate or partner a section but their department's sheet has not been imported.
                They will be picked up automatically when it is &mdash; no need to add them by hand.
            </p>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[720px]">
                <thead>
                    <tr class="bg-mist border-b border-line">
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teacher</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Department</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Status</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Sections</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Moderates</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($teachers as $teacher)
                        <tr class="border-b border-line last:border-0 hover:bg-mist/60">
                            <td class="px-4 py-3.5 font-semibold text-[14px]">{{ $teacher->full_name }}</td>
                            <td class="px-4 py-3.5 text-[13.5px]">
                                @if ($teacher->department)
                                    {{ $teacher->department->name }}
                                @else
                                    <span class="flag flag-warn">Awaiting plantilla</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-[13.5px]">
                                @if ($teacher->employment_status)
                                    <span class="pill-draft">{{ $teacher->employment_status->label() }}</span>
                                @else
                                    <span class="text-[#8a6200] text-[13px]">Not set</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right font-data">{{ $teacher->section_assignments_count }}</td>
                            <td class="px-4 py-3.5 text-right font-data">{{ $teacher->moderator_assignments_count }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <a href="{{ route('admin.teachers.edit', $teacher) }}" class="text-electric text-[13.5px] font-semibold hover:underline">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
