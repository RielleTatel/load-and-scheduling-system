<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name . ' · Review'" title="Review & Correct">
        <x-slot:actions>
            @if ($rows->isNotEmpty())
                <form method="POST" action="{{ route('chair.plantilla.confirm') }}"
                      data-confirm="Import these rows into your department roster?" data-confirm-title="Import plantilla rows" data-confirm-label="Confirm and import" data-confirm-icon="import">
                    @csrf
                    <button type="submit" class="btn-hero">Confirm &amp; import</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @if ($identityCases->isNotEmpty())
        <div class="card p-5 mb-5 border-l-4 border-amber-brand">
            <p class="font-bold text-[14px] mb-1">{{ $identityCases->count() }} teacher {{ \Illuminate\Support\Str::plural('name', $identityCases->count()) }} needs an Admin identity decision</p>
            <p class="text-slate-brand text-[13.5px] mb-3">Nothing on this sheet imports until each is decided, so no load lands on the wrong person. Tell the Admin what you know.</p>
            @foreach ($identityCases as $case)
                <div class="border-t border-line pt-3 mt-3">
                    <p class="text-[13.5px] mb-2">{{ $describe($case) }}</p>
                    @foreach ($case->chair_context ?? [] as $entry)
                        <p class="text-slate-brand text-[12.5px]">{{ $entry['name'] }}: {{ $entry['note'] }}</p>
                    @endforeach
                    <form method="POST" action="{{ route('chair.identity-cases.context', $case) }}" class="flex gap-2 mt-2">
                        @csrf
                        <input name="note" class="field-input" placeholder="e.g. Same person; the sheet misspells her surname" required>
                        <button class="btn-secondary">Send to Admin</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    @php
        $statuses = \App\Enums\EmploymentStatus::cases();
        $fieldRow = fn ($row) => is_array($row->row_json) ? $row->row_json : [];
        // "Needs attention" = the extractor's own flags (unresolved section,
        // roster/sheet conflict, ...), or still missing sections.
        $needsAttention = fn ($r) => $r->row_status === \App\Enums\ExtractionRowStatus::Flagged
            || ! empty(($fieldRow($r))['flags'] ?? [])
            || trim((string) (($fieldRow($r))['sections'] ?? '')) === '';
        $flaggedCount = $rows->filter($needsAttention)->count();
        $readyCount = $rows->count() - $flaggedCount;
    @endphp

    @if ($rows->isNotEmpty())
        {{-- Review progress strip --}}
        <div class="card px-6 py-5 mb-5 flex flex-wrap items-center gap-x-10 gap-y-4">
            <div>
                <span class="stat-mini-number">{{ $rows->count() }}</span>
                <span class="stat-mini-label">Rows extracted</span>
            </div>
            <div>
                <span class="stat-mini-number {{ $flaggedCount ? 'text-amber-brand' : '' }}">{{ $flaggedCount }}</span>
                <span class="stat-mini-label">Need attention</span>
            </div>
            <div>
                <span class="stat-mini-number {{ $readyCount ? 'text-jade' : '' }}">{{ $readyCount }}</span>
                <span class="stat-mini-label">Ready to import</span>
            </div>
            <div class="flex-1 min-w-[180px]">
                <div class="h-1.5 rounded-full bg-mist overflow-hidden flex" role="img"
                     aria-label="{{ $readyCount }} of {{ $rows->count() }} rows ready">
                    <span class="bg-jade h-full" style="width: {{ $rows->count() ? round($readyCount / $rows->count() * 100) : 0 }}%"></span>
                    <span class="bg-amber-brand h-full" style="width: {{ $rows->count() ? round($flaggedCount / $rows->count() * 100) : 0 }}%"></span>
                </div>
                <p class="text-[12.5px] text-slate-brand mt-2">
                    Check each row against your paper plantilla — row numbers match the sheet.
                    Sections are matched against the section roster automatically; a row is
                    flagged when a name can't be resolved. Enter corrections as
                    <span class="font-data">G7: Ignatius, Xavier; G9: Kostka</span>.
                </p>
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-3">
        @forelse ($rows as $row)
            @php
                $data = $fieldRow($row);
                $rowFlags = $data['flags'] ?? [];
                $flagged = $row->row_status === \App\Enums\ExtractionRowStatus::Flagged || ! empty($rowFlags);
                $sections = trim((string) ($data['sections'] ?? ''));
                $flagLabels = ['sections' => 'Sections', 'cm' => 'Class moderator', 'hc' => "Honor's class"];
            @endphp
            <details class="ledger-row @if ($flagged) ledger-row-flagged @endif" @if ($flagged) open @endif>
                <summary class="ledger-summary">
                    <span class="ledger-ordinal">{{ $loop->iteration }}</span>
                    <span class="flex-1 min-w-0">
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-bold text-[15px] text-ink truncate">
                                {{ $data['teacher_name'] ?: 'Unnamed row' }}
                            </span>
                            @if ($data['employment_status'] ?? null)
                                <span class="pill-draft">{{ $data['employment_status'] }}</span>
                            @endif
                            @if ($flagged)
                                <span class="flag flag-warn">Needs review</span>
                            @endif
                        </span>
                        <span class="block font-data text-[13px] text-slate-brand truncate mt-1">
                            @if ($sections !== '')
                                {{ $sections }}
                            @else
                                <span class="text-[#8a6200]">No sections yet</span>
                            @endif
                            @if (trim((string) ($data['other_assignment'] ?? '')) !== '')
                                &nbsp;·&nbsp;{{ $data['other_assignment'] }}
                            @endif
                        </span>
                    </span>
                    <svg class="ledger-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </summary>

                <div class="ledger-body">
                    @if (! empty($rowFlags))
                        <div class="mb-4 flex flex-col gap-1.5">
                            @foreach ($rowFlags as $field => $message)
                                <p class="text-[13px] text-[#8a6200] bg-[#fdf6e3] border border-[#f0e0a8] rounded px-3 py-2">
                                    <span class="font-semibold">{{ $flagLabels[$field] ?? ucfirst($field) }}:</span>
                                    {{ $message }}
                                </p>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('chair.plantilla.rows.update', $row) }}" class="flex flex-col gap-4">
                        @csrf @method('PATCH')
                        <input type="hidden" name="stated_totals" value="{{ $data['stated_totals'] ?? '' }}">

                        @if (trim((string) ($data['stated_totals'] ?? '')) !== '')
                            <p class="text-[12.5px] text-slate-brand">
                                <span class="font-semibold">Sheet states:</span>
                                <span class="font-data">{{ $data['stated_totals'] }}</span>
                            </p>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="field-label">Teacher name</label>
                                <input name="teacher_name" value="{{ $data['teacher_name'] ?? '' }}" class="field-input">
                            </div>
                            <div>
                                <label class="field-label">Employment status</label>
                                <select name="employment_status" class="field-input">
                                    <option value="">— select —</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->label() }}" @selected(($data['employment_status'] ?? null) === $status->label())>{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Service load (hours)</label>
                                <input name="service_load" value="{{ $data['service_load'] ?? '' }}" class="field-input" placeholder="3">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="field-label">Sections</label>
                                <input name="sections" value="{{ $data['sections'] ?? '' }}" class="field-input font-data" placeholder="G7: Ignatius, Xavier; G9: Kostka">
                            </div>
                            <div>
                                <label class="field-label">Class moderator (section)</label>
                                <input name="cm" value="{{ $data['cm'] ?? '' }}" class="field-input" placeholder="G7: Rubio">
                            </div>
                            <div>
                                <label class="field-label">Honor's class (section)</label>
                                <input name="hc" value="{{ $data['hc'] ?? '' }}" class="field-input" placeholder="G8: Ignatius">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="field-label">Other assignment</label>
                                <input name="other_assignment" value="{{ $data['other_assignment'] ?? '' }}" class="field-input" placeholder="Department Chair">
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <button type="submit" class="btn-secondary">Save row</button>
                            <button type="submit" form="delete-{{ $row->id }}" class="text-rose-brand text-[13px] font-semibold hover:underline">
                                Remove row
                            </button>
                        </div>
                    </form>
                    <form id="delete-{{ $row->id }}" method="POST" action="{{ route('chair.plantilla.rows.destroy', $row) }}" class="hidden"
                          data-confirm="Remove this row? This can’t be undone." data-confirm-title="Remove plantilla row" data-confirm-label="Remove" data-confirm-tone="danger" data-confirm-icon="remove">
                        @csrf @method('DELETE')
                    </form>
                </div>
            </details>
        @empty
            <div class="card p-10 text-center bg-parchment/60">
                <p class="font-display text-[22px] text-ink uppercase">Nothing to review</p>
                <p class="text-slate-brand text-sm mt-2">
                    Upload a plantilla PDF to extract rows, or add teachers manually below.
                </p>
                <a href="{{ route('chair.plantilla.create') }}" class="btn-primary mt-5">Upload plantilla</a>
            </div>
        @endforelse
    </div>

    {{-- Add a row manually --}}
    <div class="card p-6 mt-5 border-dashed">
        <p class="eyebrow mb-1">Manual entry</p>
        <h2 class="font-bold text-[15px] mb-4">Add a teacher</h2>
        <form method="POST" action="{{ route('chair.plantilla.rows.store') }}" class="grid gap-3 sm:grid-cols-2">
            @csrf
            <input name="teacher_name" class="field-input" placeholder="Teacher name" required>
            <select name="employment_status" class="field-input">
                <option value="">Employment status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->label() }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            <input name="sections" class="field-input font-data sm:col-span-2" placeholder="G7: Ignatius, Xavier">
            <div class="sm:col-span-2">
                <button type="submit" class="btn-secondary">Add row</button>
            </div>
        </form>
    </div>
</x-app-layout>
