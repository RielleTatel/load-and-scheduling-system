<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name" title="Teacher Roster">
        <x-slot:actions>
            @if ($submission->status->isEditable())
                <a href="{{ route('chair.teachers.create') }}" class="btn-primary">Add teacher</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[820px]">
                <thead>
                    <tr class="bg-mist border-b border-line">
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teacher</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Status</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Sections</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teaching</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Non-teaching</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Total</th>
                        <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Overload</th>
                        <th class="px-4 py-3"></th>
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
                            <td class="px-4 py-3.5 text-[13.5px] text-slate-brand">{{ $t->employment_status->label() }}</td>
                            <td class="px-4 py-3.5 text-right font-data">{{ $l['section_count'] }}</td>
                            <td class="px-4 py-3.5 text-right font-data">{{ number_format($l['teaching_hours'], 1) }}</td>
                            <td class="px-4 py-3.5 text-right font-data">{{ number_format($l['nonteaching_hours'], 1) }}</td>
                            <td class="px-4 py-3.5 text-right font-data font-semibold">{{ number_format($l['total_hours'], 1) }}</td>
                            <td class="px-4 py-3.5 text-right font-data {{ $l['overload_units'] > 0 ? 'text-rose-brand font-bold' : '' }}">{{ number_format($l['overload_units'], 2) }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <a href="{{ route('chair.teachers.edit', $t) }}" class="text-electric text-[13.5px] font-semibold hover:underline">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-slate-brand">No teachers yet. Upload your plantilla or add them manually.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
