@php
    $showUrl = $isAdmin ? route('admin.schedules.show', $timetable) : route('chair.schedules.show', $timetable);
    $exportRoute = $isAdmin ? 'admin.schedules.export' : 'chair.schedules.export';
    $exportQuery = http_build_query(request()->query());
    $pdfUrl = route($exportRoute, ['timetable' => $timetable, 'format' => 'pdf']).($exportQuery ? '?'.$exportQuery : '');
    $xlsxUrl = route($exportRoute, ['timetable' => $timetable, 'format' => 'xlsx']).($exportQuery ? '?'.$exportQuery : '');
    $editable = $isAdmin && $timetable->status === \App\Enums\TimetableStatus::Draft;
    $special = $timetable->kind === \App\Enums\TimetableKind::Special;
    $timetableLayout = app(\App\Services\Scheduling\TimetableLayout::class);
    $subjectOptions = $departments->map(fn ($department) => [
        'code' => $department->code,
        'label' => $timetableLayout->subjectName($department->code, $department->name),
    ])->values();
@endphp

<x-app-layout>
    <div data-branded-dropdowns>
    <x-page-header
        :eyebrow="($isAdmin ? 'System Administration' : auth()->user()->department->name).' · '.$timetable->school_year.' · Quarter '.$timetable->quarter"
        :title="$timetable->kind->label().' timetable · v'.$timetable->version"
    >
        <x-slot:actions>
            <a class="btn-secondary" href="{{ $pdfUrl }}">Download PDF</a>
            <a class="btn-secondary" href="{{ $xlsxUrl }}">Download Excel</a>
            <a class="btn-ghost" href="{{ $isAdmin ? route('admin.schedules.index', ['quarter' => $timetable->quarter, 'kind' => $timetable->kind->value]) : route('chair.schedules.index', ['quarter' => $timetable->quarter, 'kind' => $timetable->kind->value]) }}">Back</a>
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
            'bg-slate-100 text-slate-600' => in_array($timetable->status, [\App\Enums\TimetableStatus::Superseded, \App\Enums\TimetableStatus::Cancelled], true),
        ])>{{ $timetable->status->label() }}{{ $editable ? ' · not published' : '' }}</span>
        @if ($timetable->approved_at)
            <span class="text-sm text-slate-brand">Approved {{ $timetable->approved_at->format('M j, Y g:i A') }} by {{ $timetable->approvedBy?->name ?? 'Admin' }}</span>
        @elseif ($timetable->status === \App\Enums\TimetableStatus::Generating)
            <span class="text-sm text-slate-brand">The timetable will appear automatically when generation finishes.</span>
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
            <form method="POST" action="{{ route('admin.schedules.approve', $timetable) }}" data-confirm="Approve and publish this timetable to Department Chairs?" data-confirm-title="Publish timetable" data-confirm-label="Approve and publish" data-confirm-icon="publish">
                @csrf
                <button data-schedule-approve class="btn-primary" @disabled($hardIssueCount > 0) title="{{ $hardIssueCount ? 'Resolve all hard errors before approval.' : '' }}">Approve and publish</button>
            </form>
        @endif
    </div>

    @if ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Failed)
        <div class="card p-4 mb-5 border border-rose-200 text-sm text-rose-800">
            <strong>A new schedule could not be created.</strong>
            <p class="mt-2">{{ $generationFailureSummary }}</p>
            @if ($timetable->failure_message)
                <details class="mt-3 text-xs text-slate-brand">
                    <summary class="cursor-pointer">Technical details (for system support)</summary>
                    <p class="mt-2 break-words">{{ $timetable->failure_message }}</p>
                </details>
            @endif
        </div>
    @endif

    @if ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Cancelled)
        <div class="card p-4 mb-5 text-sm text-slate-brand">Generation was stopped. No new schedule was saved. Your existing schedules are unchanged.</div>
    @endif

    @if ($isAdmin && $editable)
        <div class="card p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-bold">Admin editing</h2>
                <p class="text-xs text-slate-brand mt-1">Click a cell to replace or swap subjects. Drag cells to swap within the same section and day. Changes save automatically; validation errors remain visible until resolved.</p>
                @if ($special)<p class="text-xs text-slate-brand mt-2">Special-schedule edits apply to Day 1–5 together, keeping the same daily subject order.</p>@endif
            </div>
        </div>
    @endif

    <div data-schedule-validation>
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
    </div>

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
                                <option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $timetableLayout->subjectName($department->code, $department->name) }}</option>
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
                        <option value="all" @selected($filters['day'] === 'all')>All days</option>
                        @foreach (range(1, 5) as $day)
                            <option value="{{ $day }}" @selected($filters['day'] === $day)>Day {{ $day }}</option>
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
                <p x-show="busy" x-cloak class="mb-3 text-sm text-slate-brand" role="status">Saving and checking the schedule…</p>
        @endif
        <div data-timetable-grid class="space-y-8">
            @foreach ($gradeGroups as $gradeCode => $gradeSections)
                <section class="card overflow-x-auto" data-grade-schedule="{{ $gradeCode }}">
                    <div class="border-b border-line bg-white px-4 py-3">
                        <h2 class="font-bold text-navy">Grade {{ substr($gradeCode, 1) }} schedule</h2>
                    </div>
                    <div class="space-y-6 pb-6">
                    @foreach ($days as $day)
                    <table class="w-full border-collapse min-w-[780px]" data-day-schedule="{{ $day }}">
                        <thead>
                            <tr class="bg-emerald-600 text-white">
                                <th colspan="{{ $gradeSections->count() + 1 }}" class="px-3 py-2 text-center text-sm font-bold tracking-wide">DAY {{ $day }}</th>
                            </tr>
                            <tr class="bg-navy text-white">
                                <th class="sticky left-0 bg-navy px-3 py-3 text-center text-xs min-w-[128px]">TIME</th>
                                @foreach ($gradeSections as $section)
                                    <th class="px-3 py-3 text-center text-xs min-w-[142px] border-l border-white/15">{{ substr($gradeCode, 1) }} - {{ $section->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($scheduleRowsByDay[$day] as $row)
                                @if ($row['type'] === 'subject')
                                    @php $period = $row['period']; @endphp
                                    <tr class="border-t border-line bg-white">
                                        <th class="sticky left-0 bg-mist px-3 py-3 text-center text-xs font-semibold text-slate-brand min-w-[128px]">{{ $row['time'] }}</th>
                                        @foreach ($gradeSections as $section)
                                            @php $entry = $grid[$section->id][$day][$period] ?? null; @endphp
                                            <td data-schedule-cell class="h-px border-l border-line align-top p-1 min-w-[142px]"
                                                @if ($canEditThisView)
                                                    @dragover="hoverDrop($event, {{ $entry?->id ?? 'null' }}, {{ $section->id }}, {{ $day }})"
                                                    @dragleave="if (!$el.contains($event.relatedTarget)) dropTarget = null"
                                                    @drop="dropSwap($event, {{ $entry?->id ?? 'null' }}, {{ $section->id }}, {{ $day }}, {{ $period }})"
                                                    :class="{ 'schedule-drop-target': dropTarget === {{ $entry?->id ?? 'null' }} && dragged }"
                                                @endif>
                                                @if ($entry)
                                                    @php $subjectName = $timetableLayout->subjectName($entry->department->code, $entry->department->name); @endphp
                                                    @if ($canEditThisView)
                                                        <button type="button" draggable="true"
                                                            @click="edit({{ $entry->id }}, {{ $section->id }}, {{ $day }}, {{ $period }}, @js($entry->department->code))"
                                                            @dragstart="startDrag($event, {{ $entry->id }}, {{ $section->id }}, {{ $day }})"
                                                            @dragend="endDrag()" :disabled="busy"
                                                            :class="{ 'schedule-drag-source': dragged?.entryId === {{ $entry->id }} }"
                                                            data-schedule-card class="schedule-edit-card w-full h-full min-h-[64px] rounded-md bg-blue-50 hover:bg-blue-100 border border-blue-100 px-2 py-2 text-center transition">
                                                            <span class="block font-bold text-[11px] leading-tight text-navy break-words">{{ $subjectName }}</span>
                                                            <span class="block text-[10px] leading-tight text-slate-brand mt-1 break-words">{{ $entry->teacher->full_name }}</span>
                                                        </button>
                                                    @else
                                                        <div data-schedule-card class="h-full min-h-[64px] rounded-md bg-mist px-2 py-2 text-center">
                                                            <span class="block font-bold text-[11px] leading-tight text-navy break-words">{{ $subjectName }}</span>
                                                            <span class="block text-[10px] leading-tight text-slate-brand mt-1 break-words">{{ $entry->teacher->full_name }}</span>
                                                        </div>
                                                    @endif
                                                @else
                                                    <div data-schedule-card class="h-full min-h-[64px] grid place-items-center text-xs text-slate-brand/40">—</div>
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
                    @endforeach
                    </div>
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
                                <option value="swap">{{ $special ? 'Swap with another period on all five days' : 'Swap with another day / period in this section' }}</option>
                            </select>
                        </label>
                        <label x-show="action === 'replace'" class="block text-sm font-semibold">New subject
                            <select x-model="subjectCode" class="form-select mt-1 w-full">
                                <template x-for="subject in subjects" :key="subject.code">
                                    <option :value="subject.code" x-text="subject.label"></option>
                                </template>
                            </select>
                        </label>
                        <div x-show="action === 'swap'" class="grid {{ $special ? 'grid-cols-1' : 'grid-cols-2' }} gap-3">
                            @unless ($special)
                            <label class="block text-sm font-semibold">Target day
                                <select x-model="targetDay" class="form-select mt-1 w-full">
                                    @foreach (range(1, 5) as $day)<option value="{{ $day }}">Day {{ $day }}</option>@endforeach
                                </select>
                            </label>
                            @endunless
                            <label class="block text-sm font-semibold">Target period
                                <select x-model="targetPeriod" class="form-select mt-1 w-full">
                                    @foreach ($periods as $period)<option value="{{ $period }}">{{ $periodTimes[$period] }}</option>@endforeach
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
        <p class="text-xs text-slate-brand mt-3">{{ $special ? 'All eight subjects meet once each day, in the same order on Day 1–5.' : 'The same seven-period bell schedule is used for every cycle day.' }} Day 1–5 labels do not correspond to weekdays.</p>
    @elseif ($isAdmin && $timetable->status === \App\Enums\TimetableStatus::Generating)
        <div class="card p-8 max-w-2xl mx-auto" x-data="timetableGeneration(@js(route('admin.schedules.progress', $timetable)))" data-generation-loader>
            <div class="flex items-center gap-3">
                <span class="schedule-spinner" aria-hidden="true"></span>
                <h2 class="font-bold text-navy" x-text="stage">Getting ready to start</h2>
            </div>
            <div class="mt-5">
                <div class="flex justify-between text-xs text-slate-brand mb-2"><span>Class periods without conflicts</span><span x-text="percent + '%'">0%</span></div>
                <div class="h-2 bg-mist rounded-full overflow-hidden" role="progressbar" aria-label="Class periods without conflicts" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full bg-electric rounded-full transition-[width] duration-700 motion-reduce:transition-none" :style="{ width: percent + '%' }"></div>
                </div>
            </div>
            <p class="text-sm text-slate-brand mt-5">Time elapsed: <span class="font-data" x-text="elapsedLabel">0:00</span></p>
            <p class="text-sm text-slate-brand mt-4">We’re arranging classes and checking teachers’ schedules. We’ll keep working until a schedule passes all checks, or you stop generation.</p>
            <p class="text-xs text-slate-brand mt-3">Keep this page open. It updates automatically, and a schedule is saved only when all checks pass.</p>
            <form method="POST" action="{{ route('admin.schedules.stop', $timetable) }}" class="mt-5" data-confirm="Stop generating this schedule? Your existing schedules will remain unchanged." data-confirm-title="Stop schedule generation" data-confirm-label="Stop generation" data-confirm-tone="danger" data-confirm-icon="stop">
                @csrf
                <button class="btn-secondary">Stop generation</button>
            </form>
            <p x-show="connectionError" x-cloak class="text-xs text-amber-800 mt-3" role="status">Trying to reconnect to check your schedule.</p>
        </div>
    @else
        <div class="card p-8 text-center text-sm text-slate-brand">There are no schedule cells to show for the selected version.</div>
    @endif

    @if ($isAdmin)
        <section class="mt-6 grid gap-5 xl:grid-cols-[1.2fr_1fr]">
            <div class="card p-5">
                <h2 class="font-bold">Quarter teacher unavailability</h2>
                <p class="text-xs text-slate-brand mt-1">These slots are hard constraints for generation and are rechecked after every draft edit.</p>
                @if ($special)<p class="text-xs text-slate-brand mt-2">Unavailable times are shared across this quarter’s schedules. For special schedules, an unavailable time on any day is avoided on all five days.</p>@endif
                <form method="POST" action="{{ route('admin.schedules.unavailability.store', $timetable) }}" class="grid gap-3 sm:grid-cols-2 mt-4">
                    @csrf
                    <label class="text-xs font-semibold text-slate-brand">Teacher
                        <select name="teacher_id" required class="form-select mt-1 w-full">
                            @foreach ($availabilityTeachers as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->full_name }}@if ($teacher->department) · {{ $timetableLayout->subjectName($teacher->department->code, $teacher->department->name) }}@endif</option>
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
                            @foreach ($periods as $period)<option value="{{ $period }}">{{ $periodTimes[$period] }}</option>@endforeach
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
                                            <form method="POST" action="{{ route('admin.schedules.unavailability.destroy', [$timetable, $slot]) }}" data-confirm="Remove this unavailable slot?" data-confirm-title="Remove unavailable slot" data-confirm-label="Remove" data-confirm-tone="danger" data-confirm-icon="remove">
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
                    @if ($special)
                    <li>All eight subjects meet once per day in 50-minute periods, with the same order on Day 1–5.</li>
                    <li>No double blocks, normal cycle-frequency patterns, or normal teacher-break rules apply.</li>
                    <li>Teacher and section conflicts, current assignments, submitted plantillas, and unavailable times are still checked.</li>
                    <li>Examen starts at 3:50; classroom clean-up ends with dismissal at 4:10. There is no remediation row.</li>
                    @else
                    <li>One normal timetable is repeated across five cycles; each cycle is Day 1–5.</li>
                    <li>Seven 50-minute periods each day; recess and lunch are breaks, not schedulable periods.</li>
                    <li>English, Math, and Science meet five times per cycle; the other subjects occupy four periods.</li>
                    <li>TLE and MAPEH use one double block and two single periods; Grade 10 English uses two doubles and one single.</li>
                    <li>Single-period subjects meet at most once per day; allowed double blocks cannot be split across the day.</li>
                    @endif
                    <li>Meeting rules and facility capacities remain pending confirmation and are not enforced.</li>
                </ul>
            </aside>
        </section>
    @endif
    </div>
</x-app-layout>
