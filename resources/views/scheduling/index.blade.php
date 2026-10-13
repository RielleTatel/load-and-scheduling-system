<x-app-layout>
    <div data-branded-dropdowns>
    <x-page-header
        :eyebrow="($isAdmin ? 'System Administration' : auth()->user()->department->name).' · '.$schoolYear"
        :title="$isAdmin ? $kind->label().' timetables' : 'Approved timetable'"
    >
        <x-slot:actions>
            <form method="GET" action="{{ $isAdmin ? route('admin.schedules.index') : route('chair.schedules.index') }}" class="flex items-center gap-2">
                <label for="schedule-kind" class="text-sm text-slate-brand">Schedule</label>
                <select id="schedule-kind" name="kind" class="form-select" onchange="this.form.submit()">
                    <option value="normal" @selected($kind === \App\Enums\TimetableKind::Normal)>Normal</option>
                    <option value="special" @selected($kind === \App\Enums\TimetableKind::Special)>Special review</option>
                </select>
                <label for="quarter" class="text-sm text-slate-brand">Quarter</label>
                <select id="quarter" name="quarter" class="form-select" onchange="this.form.submit()">
                    @foreach (range(1, 4) as $q)
                        <option value="{{ $q }}" @selected($quarter === $q)>Quarter {{ $q }}</option>
                    @endforeach
                </select>
            </form>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @if ($isAdmin)
        <section class="card p-5 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="font-bold text-base">Generation readiness</h2>
                    <p class="text-sm text-slate-brand mt-1">
                        {{ $readiness['ready'] ? 'All plantillas and section-teacher assignments are ready.' : count($readiness['blockers']).' items need attention before generation.' }}
                    </p>
                </div>
                @if ($readiness['ready'])
                    <form method="POST" action="{{ route('admin.schedules.generate') }}" data-confirm="Generate a new {{ $kind->value }} timetable for Quarter {{ $quarter }}?" data-confirm-title="Generate {{ $kind->value }} timetable" data-confirm-label="Generate" data-confirm-icon="calendar">
                        @csrf
                        <input type="hidden" name="quarter" value="{{ $quarter }}">
                        <input type="hidden" name="kind" value="{{ $kind->value }}">
                        <button class="btn-primary">Generate {{ $kind->value }} schedule</button>
                    </form>
                @else
                    <span class="px-3 py-2 rounded-lg bg-amber-50 text-amber-800 text-sm font-semibold">Generation blocked</span>
                @endif
            </div>

            @if ($kind === \App\Enums\TimetableKind::Special)
                <p class="text-xs text-slate-brand mt-3">For examination review: all eight subjects meet once daily in 50-minute periods. Each section follows the same subject order on Day 1–5. Normal schedules remain unchanged.</p>
            @else
                <p class="text-xs text-slate-brand mt-3">Uses the Excel workbook as a starting reference, with adjustments for current teachers and scheduling rules. An existing valid schedule may be reused when needed.</p>
            @endif

            @if (! empty($readiness['warnings']))
                <details class="mt-4 border-t border-line pt-4 text-sm">
                    <summary class="cursor-pointer text-slate-brand">{{ count($readiness['warnings']) }} teacher identity note(s) &mdash; these do not block generation</summary>
                    <ul class="space-y-1 mt-2 max-h-56 overflow-y-auto">
                        @foreach ($readiness['warnings'] as $warning)
                            <li class="text-slate-brand">{{ $warning['message'] }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('admin.teacher-readiness.index') }}" class="text-electric font-semibold hover:underline">Teacher identity readiness</a>
                </details>
            @endif

            @if (! $readiness['ready'])
                <div class="mt-4 border-t border-line pt-4 max-h-72 overflow-y-auto">
                    <ul class="space-y-2 text-sm">
                        @foreach ($readiness['blockers'] as $blocker)
                            <li class="flex gap-2 text-slate-brand">
                                <span class="text-amber-600" aria-hidden="true">●</span>
                                <span>{{ $blocker['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-line flex items-center justify-between">
                <div>
                    <h2 class="font-bold">Quarter {{ $quarter }} versions</h2>
                    <p class="text-sm text-slate-brand mt-1">Drafts stay private to Admin until approved.</p>
                </div>
                <span class="text-xs text-slate-brand">{{ $versions->count() }} version(s)</span>
            </div>
            @if ($versions->isEmpty())
                <p class="px-5 py-8 text-sm text-slate-brand">No timetable has been generated for this quarter.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-sm">
                        <thead class="bg-mist text-slate-brand text-xs uppercase tracking-wide">
                            <tr>
                                <th class="text-left px-5 py-3">Version</th>
                                <th class="text-left px-5 py-3">Status</th>
                                <th class="text-left px-5 py-3">Created by</th>
                                <th class="text-right px-5 py-3">Hard errors</th>
                                <th class="text-right px-5 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($versions as $version)
                                <tr class="border-t border-line">
                                    <td class="px-5 py-3 font-semibold">v{{ $version->version }}</td>
                                    <td class="px-5 py-3">
                                        <span @class([
                                            'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                            'bg-blue-50 text-blue-800' => $version->status === \App\Enums\TimetableStatus::Generating,
                                            'bg-amber-50 text-amber-800' => $version->status === \App\Enums\TimetableStatus::Draft,
                                            'bg-green-50 text-green-800' => $version->status === \App\Enums\TimetableStatus::Approved,
                                            'bg-slate-100 text-slate-600' => in_array($version->status, [\App\Enums\TimetableStatus::Superseded, \App\Enums\TimetableStatus::Failed, \App\Enums\TimetableStatus::Cancelled], true),
                                        ])>{{ $version->status->label() }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-slate-brand">{{ $version->createdBy?->name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right font-data {{ $version->hard_issue_count ? 'text-rose-brand font-bold' : '' }}">{{ $version->hard_issue_count }}</td>
                                    <td class="px-5 py-3 text-right"><a class="btn-ghost" href="{{ route('admin.schedules.show', $version) }}">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @elseif ($schedule)
        <section class="card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-brand">{{ $schoolYear }} · Quarter {{ $quarter }}</p>
                    <h2 class="text-xl font-bold mt-1">Approved {{ $kind->value }} timetable · v{{ $schedule->version }}</h2>
                    <p class="text-sm text-slate-brand mt-2">
                        Published {{ $schedule->approved_at?->format('M j, Y g:i A') ?? 'date unavailable' }}
                    </p>
                </div>
                <a class="btn-primary" href="{{ route('chair.schedules.show', $schedule) }}">View timetable</a>
            </div>
        </section>
    @else
        <section class="card p-8 text-center">
            <h2 class="font-bold text-lg">No approved timetable yet</h2>
            <p class="text-sm text-slate-brand mt-2">The approved schedule for Quarter {{ $quarter }} will appear here after the Admin publishes it.</p>
        </section>
    @endif
    </div>
</x-app-layout>
