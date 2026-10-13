<x-app-layout>
    <x-page-header eyebrow="Teacher Directory" title="Identity readiness &mdash; {{ $report['school_year'] }}" />

    <p class="text-slate-brand text-sm mb-5 max-w-3xl">
        Teachers with any load or role in {{ $report['school_year'] }}. An open identity case blocks schedule generation,
        because the record may be two people. Pending verification is shown for follow-up but never blocks, and an
        official directory is not required. This does not check workloads, sections or rooms.
    </p>

    <div class="card overflow-hidden">
        <table class="w-full border-collapse text-[13.5px]">
            <thead>
                <tr class="bg-mist border-b border-line">
                    <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teacher</th>
                    <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Department</th>
                    <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Needs</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['teachers'] as $row)
                    <tr class="border-b border-line last:border-0">
                        <td class="px-4 py-3 font-semibold"><a class="hover:underline" href="{{ route('admin.teachers.edit', $row['teacher']) }}">{{ $row['teacher']->full_name }}</a></td>
                        <td class="px-4 py-3">{{ $row['teacher']->department?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @forelse ($row['reasons'] as $reason)
                                <div class="{{ $reason['level'] === 'blocking' ? 'text-rose-brand font-semibold' : 'text-[#8a6200]' }}">
                                    @isset($reason['case_id'])
                                        <a class="hover:underline" href="{{ route('admin.identity-cases.show', $reason['case_id']) }}">{{ $reason['message'] }}</a>
                                    @else
                                        {{ $reason['message'] }}
                                    @endisset
                                </div>
                            @empty
                                <span class="text-jade">Ready</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-slate-brand">No teacher has a load or role in this school year yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
