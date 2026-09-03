<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name" title="Submit for Review" />

    <x-flash />

    @php $editable = $submission->status->isEditable(); @endphp

    @if (! $editable)
        <div class="card p-6 flex items-center gap-3">
            <x-status-pill :status="$submission->status" />
            <p class="text-slate-brand text-sm">
                This dataset was submitted{{ $submission->submitted_at ? ' on ' . $submission->submitted_at->format('M j, Y') : '' }} and is locked. The Academic Coordinator can return it for changes.
            </p>
        </div>
    @else
        @if ($submission->status === \App\Enums\SubmissionStatus::Returned)
            <div class="card p-6 mb-5 border-l-4 border-amber-brand bg-[#fdf6e3]">
                <p class="font-bold text-[13px] uppercase tracking-[0.06em] text-[#8a6200] mb-1">
                    Returned{{ $submission->returnedBy ? ' by ' . $submission->returnedBy->name : '' }}
                </p>
                <p class="text-ink text-sm">{{ $submission->returned_comment ?: 'No comment was left.' }}</p>
            </div>
        @endif

        <p class="text-slate-brand text-sm mb-5 max-w-2xl">
            Review the load summary below. Submitting hands your department's data to the Academic Coordinator and locks editing until it's returned.
        </p>

        <div class="card overflow-hidden mb-5">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[680px]">
                    <thead>
                        <tr class="bg-mist border-b border-line">
                            <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Teacher</th>
                            <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Sections</th>
                            <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Total hours</th>
                            <th class="text-right font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Overload</th>
                            <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($teachers as $entry)
                            @php $t = $entry['model']; $l = $entry['load']; @endphp
                            <tr class="border-b border-line last:border-0">
                                <td class="px-4 py-3.5 font-semibold text-[14px]">{{ $t->full_name }}</td>
                                <td class="px-4 py-3.5 text-right font-data">{{ $l['section_count'] }}</td>
                                <td class="px-4 py-3.5 text-right font-data font-semibold">{{ number_format($l['total_hours'], 1) }}</td>
                                <td class="px-4 py-3.5 text-right font-data {{ $l['overload_units'] > 0 ? 'text-rose-brand font-bold' : '' }}">{{ number_format($l['overload_units'], 2) }}</td>
                                <td class="px-4 py-3.5"><div class="flex flex-wrap gap-1"><x-load-flags :flags="$l['flags']" /></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-slate-brand">No teachers to submit yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <form method="POST" action="{{ route('chair.submission.store') }}"
              onsubmit="return confirm('Submit your department dataset? Editing will be locked until it is returned.')">
            @csrf
            <button type="submit" class="btn-hero">Submit for review</button>
            <a href="{{ route('chair.dashboard') }}" class="btn-ghost ml-2">Back</a>
        </form>
    @endif
</x-app-layout>
