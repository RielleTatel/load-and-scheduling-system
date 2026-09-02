<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Section Roster">
        <x-slot:actions>
            <a href="{{ route('admin.sections.create') }}" class="btn-primary">Add section</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <p class="text-slate-brand text-sm mb-5 max-w-3xl">
        The school's {{ $sections->flatten()->count() }} sections, from the registrar's list of class moderators.
        Plantilla import resolves each section's grade from its name, so <strong>names must stay unique across grades</strong>.
        The moderator and teacher-partner columns are the only complete record of those assignments &mdash; four of the
        seven department sheets never state a moderator.
    </p>

    @foreach ($sections as $grade => $rows)
        <div class="card overflow-hidden mb-5">
            <div class="px-4 py-3 bg-mist border-b border-line flex items-center justify-between">
                <span class="font-bold text-[13px] uppercase tracking-[0.06em] text-slate-brand">Grade {{ substr($grade, 1) }}</span>
                <span class="text-slate-brand text-[12.5px]">{{ $rows->count() }} sections</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[820px]">
                    <thead>
                        <tr class="border-b border-line">
                            <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Section</th>
                            <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Room</th>
                            <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Class moderator</th>
                            <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teacher-partner</th>
                            <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Subjects</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $section)
                            <tr class="border-b border-line last:border-0 hover:bg-mist/60">
                                <td class="px-4 py-3.5 font-semibold text-[14px]">
                                    {{ $section->name }}
                                    @if ($section->is_magis)
                                        <span class="flag flag-ok ml-1.5">Magis</span>
                                    @endif
                                    <span class="block text-slate-brand font-normal text-[12.5px]">{{ $section->full_name }}</span>
                                </td>
                                <td class="px-4 py-3.5 font-data text-[13.5px]">{{ $section->room ?: '—' }}</td>
                                <td class="px-4 py-3.5 text-[13.5px]">
                                    {{ $section->moderator_name ?: '—' }}
                                    @unless ($section->moderatorAssignment)
                                        <span class="flag flag-warn ml-1.5">Not yet imported</span>
                                    @endunless
                                </td>
                                <td class="px-4 py-3.5 text-[13.5px]">{{ $section->teacher_partner_name ?: '—' }}</td>
                                <td class="px-4 py-3.5 text-right font-data">{{ $section->teacher_assignments_count }}</td>
                                <td class="px-4 py-3.5 text-right">
                                    <a href="{{ route('admin.sections.edit', $section) }}" class="text-electric text-[13.5px] font-semibold hover:underline">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</x-app-layout>
