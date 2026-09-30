@php
    $showUrl = $isAdmin ? route('admin.schedules.show', $timetable) : route('chair.schedules.show', $timetable);
    $exportRoute = $isAdmin ? 'admin.schedules.export' : 'chair.schedules.export';
    $exportQuery = http_build_query(request()->query());
    $pdfUrl = route($exportRoute, ['timetable' => $timetable, 'format' => 'pdf']).($exportQuery ? '?'.$exportQuery : '');
    $xlsxUrl = route($exportRoute, ['timetable' => $timetable, 'format' => 'xlsx']).($exportQuery ? '?'.$exportQuery : '');
    $editable = $isAdmin && $timetable->status === \App\Enums\TimetableStatus::Draft;
    $timetableLayout = app(\App\Services\Scheduling\TimetableLayout::class);
    $subjectOptions = $departments->map(fn ($department) => [
        'code' => $department->code,
        'label' => $timetableLayout->subjectName($department->code, $department->name),
    ])->values();
@endphp

<x-app-layout>
    <x-page-header
        :eyebrow="($isAdmin ? 'System Administration' : auth()->user()->department->name).' · '.$timetable->school_year.' · Quarter '.$timetable->quarter"
        :title="'Normal timetable · v'.$timetable->version"
    >
        <x-slot:actions>
            <a class="btn-secondary" href="{{ $pdfUrl }}">Download PDF</a>
            <a class="btn-secondary" href="{{ $xlsxUrl }}">Download Excel</a>
            <a class="btn-ghost" href="{{ $isAdmin ? route('admin.schedules.index', ['quarter' => $timetable->quarter]) : route('chair.schedules.index', ['quarter' => $timetable->quarter]) }}">Back</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="flex flex-wrap items-center gap-3 mb-5">
        <span @class([
            'inline-flex rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide',
            'bg-blue-50 text-blue-800' => $timetable->status === \App\Enums\TimetableStatus::Generating,
            'bg-amber-50 text-amber-800' => $timetable->status === \App\Enums\TimetableStatus::Draft,
            'bg-green-50 text-green-800' => $timetable->status === \App\Enums\TimetableStatus::Approved,
            'bg-rose-50 text-rose-800' => $timetable->status === \App\Enums\TimetableStatus::Failed,
            'bg-slate-100 text-slate-600' => $timetable->status === \App\Enums\TimetableStatus::Superseded,
        ])>{{ $timetable->status->label() }}{{ $editable ? ' · not published' : '' }}</span>
        @if ($timetable->approved_at)
            <span class="text-sm text-slate-brand">Approved {{ $timetable->approved_at->format('M j, Y g:i A') }} by {{ $timetable->approvedBy?->name ?? 'Admin' }}</span>
        @elseif ($timetable->status === \App\Enums\TimetableStatus::Generating)
            <span class="text-sm text-slate-brand">The generator is searching for the lowest-error complete timetable. Refresh this page in a little while.</span>
        @endif
        @if ($isAdmin && $editable)
            <button type="button" class="btn-ghost" @click="$dispatch('schedule-undo')">Undo last edit</button>
        @endif
        @if ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Approved)
            <form method="POST" action="{{ route('admin.schedules.clone', $timetable) }}">
                @csrf
                <button class="btn-primary">Create editable revision</button>
            </form>
        @endif
        @if ($isAdmin && $editable)
            <form method="POST" action="{{ route('admin.schedules.approve', $timetable) }}" onsubmit="return confirm('Approve and publish this timetable to Department Chairs?')">
                @csrf
                <button class="btn-primary" @disabled($hardIssueCount > 0) title="{{ $hardIssueCount ? 'Resolve all hard errors before approval.' : '' }}">Approve and publish</button>
            </form>
        @endif
    </div>

    @if ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Failed)
        <div class="card p-4 mb-5 border border-rose-200 text-sm text-rose-800">
            <strong>Generation failed.</strong> {{ $timetable->failure_message ?: 'Review data readiness and retry generation.' }}
        </div>
    @endif

    @if ($isAdmin && $editable)
        <div class="card p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-bold">Admin editing</h2>
                <p class="text-xs text-slate-brand mt-1">Click a cell to replace or swap subjects. Drag cells to swap within the same section and day. Changes save automatically; validation errors remain visible until resolved.</p>
            </div>
            <span class="text-xs text-slate-brand">Edit sequence {{ $timetable->edit_sequence }}</span>
        </div>
    @endif

    @if ($isAdmin && $hardIssues->isNotEmpty())
        <section class="card p-5 mb-5 border border-rose-200">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-bold text-rose-800">{{ $hardIssueCount }} hard validation error(s)</h2>
                <p class="text-xs text-slate-brand">Drafts may contain errors; approval is blocked until all are resolved.</p>
            </div>
            <ul class="mt-3 space-y-2 max-h-64 overflow-y-auto text-sm">
                @foreach ($hardIssues as $issue)
                    <li class="flex gap-2">
                        <span class="font-mono text-xs text-rose-700">{{ str_replace('_', ' ', $issue->code) }}</span>
                        <span class="text-slate-brand">{{ $issue->message }}</span>
                        @if ($issue->section)
                            <span class="text-xs text-slate-brand">{{ $issue->section->grade_level->value }} {{ $issue->section->name }}</span>
                        @endif
                        @if ($issue->teacher)
                            <span class="text-xs text-slate-brand">{{ $issue->teacher->full_name }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @elseif ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Draft)
        <div class="card p-4 mb-5 border border-green-200 text-sm text-green-800">No hard validation errors. This draft is ready to approve.</div>
    @endif

    @if ($sections->isNotEmpty() && $hasEntries)
        <section class="card p-4 mb-5">
            <form method="GET" action="{{ $showUrl }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
                @if (! $isAdmin)
                    <label class="block text-xs font-semibold text-slate-brand">View scope
                        <select name="scope" class="form-select mt-1 w-full" onchange="this.form.submit()">
                            <option value="department" @selected($filters['scope'] === 'department')>My department</option>
                            <option value="school" @selected($filters['scope'] === 'school')>Whole school</option>
                        </select>
                    </label>
                @endif
                <label class="block text-xs font-semibold text-slate-brand">Grade
                    <select name="grade" data-schedule-filter="grade" class="form-select mt-1 w-full">
                        <option value="all" @selected($filters['grade'] === 'all')>All grades</option>
                        @foreach (['G7' => 'Grade 7', 'G8' => 'Grade 8', 'G9' => 'Grade 9', 'G10' => 'Grade 10'] as $code => $label)
                            <option value="{{ $code }}" @selected($filters['grade'] === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-semibold text-slate-brand">Section
                    <select name="section_id" class="form-select mt-1 w-full">
                        <option value="">All sections</option>
                        @foreach ($sectionOptions as $option)
                            <option value="{{ $option->id }}" @selected($filters['section_id'] === $option->id)>{{ $option->grade_level->value }} · {{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($isAdmin || $filters['scope'] === 'school')
                    <label class="block text-xs font-semibold text-slate-brand">Department / subject
                        <select name="department_id" class="form-select mt-1 w-full">
                            <option value="">All departments</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="block text-xs font-semibold text-slate-brand">Teacher
                    <select name="teacher_id" class="form-select mt-1 w-full">
                        <option value="">All teachers</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}" @selected($filters['teacher_id'] === $teacher->id)>{{ $teacher->full_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-semibold text-slate-brand">Cycle day
                    <select name="day" data-schedule-filter="day" class="form-select mt-1 w-full">
                        @foreach (range(1, 5) as $day)
                            <option value="{{ $day }}" @selected($displayDay === $day)>Day {{ $day }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="sm:col-span-2 lg:col-span-6 flex gap-2">
                    <button class="btn-secondary">Apply filters</button>
                    <a class="btn-ghost" href="{{ $showUrl }}">Clear filters</a>
                </div>
            </form>
        </section>

        @php $canEditThisView = $editable && $hasEntries; @endphp
        @if ($canEditThisView)
            <div x-data="timetableEditor({
                sequence: {{ $timetable->edit_sequence }},
                subjects: @js($subjectOptions),
                updateTemplate: @js(route('admin.schedules.entries.update', ['timetable' => $timetable, 'entry' => 'ENTRY_ID'])),
                undoUrl: @js(route('admin.schedules.undo', $timetable)),
            })" @schedule-undo.window="undo()">
                <div x-show="error" x-text="error" class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert"></div>
        @endif
        <div data-timetable-grid class="space-y-8">
            @foreach ($gradeGroups as $gradeCode => $gradeSections)
                <section class="card overflow-x-auto" data-grade-schedule="{{ $gradeCode }}">
                    <div class="border-b border-line bg-white px-4 py-3">
                        <h2 class="font-bold text-navy">Grade {{ substr($gradeCode, 1) }} schedule</h2>
                    </div>
                    <table class="w-full border-collapse min-w-[780px]">
                        <thead>
                            <tr class="bg-emerald-600 text-white">
                                <th colspan="{{ $gradeSections->count() + 1 }}" class="px-3 py-2 text-center text-sm font-bold tracking-wide">DAY {{ $displayDay }}</th>
                            </tr>
                            <tr class="bg-navy text-white">
                                <th class="sticky left-0 bg-navy px-3 py-3 text-center text-xs min-w-[128px]">TIME</th>
                                @foreach ($gradeSections as $section)
                                    <th class="px-3 py-3 text-center text-xs min-w-[142px] border-l border-white/15">{{ substr($gradeCode, 1) }} - {{ $section->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($scheduleRows as $row)
                                @if ($row['type'] === 'subject')
                                    @php $period = $row['period']; @endphp
                                    <tr class="border-t border-line bg-white">
                                        <th class="sticky left-0 bg-mist px-3 py-3 text-center text-xs font-semibold text-slate-brand min-w-[128px]">{{ $row['time'] }}</th>
                                        @foreach ($gradeSections as $section)
                                            @php $entry = $grid[$section->id][$displayDay][$period] ?? null; @endphp
                                            <td class="border-l border-line align-top p-1 min-w-[142px]"
                                                @if ($canEditThisView) @dragover.prevent @drop="dropSwap($event, {{ $entry?->id ?? 'null' }}, {{ $section->id }}, {{ $displayDay }}, {{ $period }})" @endif>
                                                @if ($entry)
                                                    @php $subjectName = $timetableLayout->subjectName($entry->department->code, $entry->department->name); @endphp
                                                    @if ($canEditThisView)
                                                        <button type="button" draggable="true"
                                                            @click="edit({{ $entry->id }}, {{ $section->id }}, {{ $displayDay }}, {{ $period }}, @js($entry->department->code))"
                                                            @dragstart="$event.dataTransfer.setData('application/json', JSON.stringify({entryId: {{ $entry->id }}, sectionId: {{ $section->id }}, day: {{ $displayDay }}})); $event.dataTransfer.effectAllowed = 'move'"
                                                            class="w-full min-h-[64px] rounded-md bg-blue-50 hover:bg-blue-100 border border-blue-100 px-2 py-2 text-center transition">
                                                            <span class="block font-bold text-[11px] leading-tight text-navy break-words">{{ $subjectName }}</span>
                                                            <span class="block text-[10px] leading-tight text-slate-brand mt-1 break-words">{{ $entry->teacher->full_name }}</span>
                                                        </button>
                                                    @else
                                                        <div class="min-h-[64px] rounded-md bg-mist px-2 py-2 text-center">
                                                            <span class="block font-bold text-[11px] leading-tight text-navy break-words">{{ $subjectName }}</span>
                                                            <span class="block text-[10px] leading-tight text-slate-brand mt-1 break-words">{{ $entry->teacher->full_name }}</span>
                                                        </div>
                                                    @endif
                                                @else
                                                    <div class="min-h-[64px] grid place-items-center text-xs text-slate-brand/40">—</div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @else
                                    <tr @class([
                                        'border-t border-line',
                                        'bg-rose-50 text-rose-900' => $row['type'] === 'break',
                                        'bg-slate-50 text-navy' => $row['type'] === 'post_class',
                                    ])>
                                        <th class="sticky left-0 px-3 py-2 text-center text-xs font-semibold bg-inherit">{{ $row['time'] }}</th>
                                        <td colspan="{{ max($gradeSections->count(), 1) }}" class="border-l border-line px-3 py-2 text-center text-xs font-bold tracking-wide">{{ $row['label'] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>
        <!-- timetable-grid-end -->
        @if ($canEditThisView)
            <div x-show="open" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-navy/50 p-4" @keydown.escape.window="open = false">
                <div class="card w-full max-w-lg p-6" @click.outside="open = false">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-bold text-lg">Edit timetable cell</h2>
                            <p class="text-xs text-slate-brand mt-1">Section <span x-text="sectionId"></span> · Day <span x-text="sourceDay"></span> · Period <span x-text="sourcePeriod"></span></p>
                        </div>
                        <button type="button" class="text-slate-brand" aria-label="Close" @click="open = false">✕</button>
                    </div>
                    <div class="mt-5 space-y-4">
                        <label class="block text-sm font-semibold">Action
                            <select x-model="action" class="form-select mt-1 w-full">
                                <option value="replace">Replace this period's subject</option>
                                <option value="swap">Swap with another day / period in this section</option>
                            </select>
                        </label>
                        <label x-show="action === 'replace'" class="block text-sm font-semibold">New subject
                            <select x-model="subjectCode" class="form-select mt-1 w-full">
                                <template x-for="subject in subjects" :key="subject.code">
                                    <option :value="subject.code" x-text="subject.label"></option>
                                </template>
                            </select>
                        </label>
                        <div x-show="action === 'swap'" class="grid grid-cols-2 gap-3">
                            <label class="block text-sm font-semibold">Target day
                                <select x-model="targetDay" class="form-select mt-1 w-full">
                                    @foreach (range(1, 5) as $day)<option value="{{ $day }}">Day {{ $day }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-semibold">Target period
                                <select x-model="targetPeriod" class="form-select mt-1 w-full">
                                    @foreach (range(1, 7) as $period)<option value="{{ $period }}">Period {{ $period }}</option>@endforeach
                                </select>
                            </label>
                        </div>
                        <p x-show="error" x-text="error" class="text-sm text-rose-brand"></p>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" class="btn-ghost" @click="open = false">Cancel</button>
                            <button type="button" class="btn-primary" :disabled="busy" @click="save()" x-text="busy ? 'Saving…' : 'Save change'"></button>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        @endif
        <p class="text-xs text-slate-brand mt-3">The same seven-period bell schedule is used for every cycle day. Day 1–5 labels do not correspond to weekdays.</p>
    @elseif ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Generating)
        <div class="card p-8 text-center text-sm text-slate-brand">Generating the normal timetable. This page will show it when the background job finishes.</div>
    @else
        <div class="card p-8 text-center text-sm text-slate-brand">There are no schedule cells to show for the selected version.</div>
    @endif

    @if ($isAdmin)
        <section class="mt-6 grid gap-5 xl:grid-cols-[1.2fr_1fr]">
            <div class="card p-5">
                <h2 class="font-bold">Quarter teacher unavailability</h2>
                <p class="text-xs text-slate-brand mt-1">These slots are hard constraints for generation and are rechecked after every draft edit.</p>
                <form method="POST" action="{{ route('admin.schedules.unavailability.store', $timetable) }}" class="grid gap-3 sm:grid-cols-2 mt-4">
                    @csrf
                    <label class="text-xs font-semibold text-slate-brand">Teacher
                        <select name="teacher_id" required class="form-select mt-1 w-full">
                            @foreach ($availabilityTeachers as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->full_name }} · {{ $teacher->department?->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-brand">Cycle day
                        <select name="day" required class="form-select mt-1 w-full">
                            @foreach (range(1, 5) as $day)<option value="{{ $day }}">Day {{ $day }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-brand">Period
                        <select name="period" required class="form-select mt-1 w-full">
                            @foreach (range(1, 7) as $period)<option value="{{ $period }}">Period {{ $period }} · {{ $periodTimes[$period] }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-brand">Reason (optional)
                        <input name="reason" maxlength="255" class="form-input mt-1 w-full" placeholder="Scheduling accommodation">
                    </label>
                    <div class="sm:col-span-2"><button class="btn-secondary">Add unavailable slot</button></div>
                </form>

                @if ($availability->isNotEmpty())
                    <div class="mt-5 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead><tr class="text-left text-slate-brand"><th class="py-2">Teacher</th><th>Day</th><th>Period</th><th>Reason</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($availability as $slot)
                                    <tr class="border-t border-line">
                                        <td class="py-2">{{ $slot->teacher?->full_name ?? 'Unknown teacher' }}</td>
                                        <td>Day {{ $slot->day }}</td>
                                        <td>P{{ $slot->period }}</td>
                                        <td>{{ $slot->reason ?: '—' }}</td>
                                        <td class="text-right">
                                            <form method="POST" action="{{ route('admin.schedules.unavailability.destroy', [$timetable, $slot]) }}" onsubmit="return confirm('Remove this unavailable slot?')">
                                                @csrf @method('DELETE')
                                                <button class="text-rose-brand hover:underline">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            <aside class="card p-5">
                <h2 class="font-bold">Schedule rules in this version</h2>
                <ul class="mt-3 space-y-2 text-sm text-slate-brand">
                    <li>One normal timetable is repeated across five cycles; each cycle is Day 1–5.</li>
                    <li>Seven 50-minute periods each day; recess and lunch are breaks, not schedulable periods.</li>
                    <li>English, Math, and Science meet five times per cycle; the other subjects occupy four periods.</li>
                    <li>TLE and MAPEH use one double block and two single periods; Grade 10 English uses two doubles and one single.</li>
                    <li>Meeting blocks and the special examination/review timetable remain out of scope for this version.</li>
                </ul>
            </aside>
        </section>
    @endif
</x-app-layout>
