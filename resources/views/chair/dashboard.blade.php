<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name . ' · ' . $submission->school_year" title="Department Dashboard">
        <x-slot:actions>
            @if ($submission->status->isEditable())
                <a href="{{ route('chair.submission.show') }}" class="btn-hero">Submit for review</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    {{-- Status banner --}}
    <div class="card p-5 mb-5 flex flex-wrap items-center gap-3">
        <x-status-pill :status="$submission->status" />
        <span class="text-sm text-slate-brand">
            {{ $teacherCount }} teachers · {{ $sectionsCovered }} of {{ $totalSections }} sections covered
        </span>
        @if ($submission->status === \App\Enums\SubmissionStatus::Returned && $submission->returned_comment)
            <p class="w-full text-sm text-rose-brand mt-1">Returned: {{ $submission->returned_comment }}</p>
        @endif
    </div>

    {{-- Stat tiles --}}
    <div class="grid gap-5 sm:grid-cols-3 mb-6">
        <x-stat-tile label="Teachers" :value="$teacherCount" note="In your department" />
        <x-stat-tile label="Sections covered" :value="$sectionsCovered" tone="navy" note="With an assigned teacher" />
        <x-stat-tile label="Flags" :value="$flaggedCount" tone="amber" note="Teachers needing attention" />
    </div>

    {{-- Quick actions --}}
    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('chair.plantilla.create') }}" class="btn-secondary">Upload plantilla</a>
        <a href="{{ route('chair.teachers.index') }}" class="btn-secondary">Teacher roster</a>
        <a href="{{ route('chair.assignments.index') }}" class="btn-secondary">Section assignments</a>
    </div>

    {{-- Per-teacher load --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[760px]">
                <thead>
                    <tr class="bg-mist border-b border-line">
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teacher</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Sections</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teaching</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Total</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Overload</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($teachers as $entry)
                        @php $t = $entry['model']; $l = $entry['load']; @endphp
                        <tr class="border-b border-line last:border-0 hover:bg-mist/60">
                            <td class="px-4 py-3.5">
                                <div class="font-semibold text-[14px]">{{ $t->full_name }}</div>
                                @if (! empty($l['flags']))
                                    <div class="flex flex-wrap gap-1 mt-1"><x-load-flags :flags="$l['flags']" /></div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right font-data">{{ $l['section_count'] }}</td>
                            <td class="px-4 py-3.5 text-right font-data">{{ number_format($l['teaching_hours'], 1) }}</td>
                            <td class="px-4 py-3.5 text-right font-data font-semibold">{{ number_format($l['total_hours'], 1) }}</td>
                            <td class="px-4 py-3.5 text-right font-data {{ $l['overload_units'] > 0 ? 'text-rose-brand font-bold' : '' }}">{{ number_format($l['overload_units'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-brand">No teachers yet. Upload your plantilla to get started.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
