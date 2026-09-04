<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Review Roster">
        <x-slot:actions>
            @if ($rows->isNotEmpty())
                <form method="POST" action="{{ route('admin.roster.confirm') }}"
                      onsubmit="return confirm('Import this roster as the section list for {{ $import->school_year }}?')">
                    @csrf
                    <button type="submit" class="btn-hero">Confirm &amp; import</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @php
        $flaggedCount = $rows->filter(fn ($r) => ! empty(($r->row_json['flags'] ?? [])))->count();
    @endphp

    @if ($rows->isNotEmpty())
        <div class="card px-6 py-5 mb-5 flex flex-wrap items-center gap-x-10 gap-y-4">
            <div>
                <span class="stat-mini-number">{{ $rows->count() }}</span>
                <span class="stat-mini-label">Sections extracted</span>
            </div>
            <div>
                <span class="stat-mini-number {{ $flaggedCount ? 'text-amber-brand' : '' }}">{{ $flaggedCount }}</span>
                <span class="stat-mini-label">Need attention</span>
            </div>
            <p class="text-[12.5px] text-slate-brand flex-1 min-w-[220px]">
                Short names are how every plantilla resolves its sections &mdash; confirm each one
                against the roster before importing.
            </p>
        </div>
    @endif

    <div class="flex flex-col gap-3">
        @forelse ($rows as $row)
            @php
                $data = is_array($row->row_json) ? $row->row_json : [];
                $flags = $data['flags'] ?? [];
            @endphp
            <details class="ledger-row @if ($flags) ledger-row-flagged @endif" @if ($flags) open @endif>
                <summary class="ledger-summary">
                    <span class="ledger-ordinal">{{ $loop->iteration }}</span>
                    <span class="flex-1 min-w-0">
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-bold text-[15px] text-ink truncate">
                                {{ $data['grade_level'] ?? '' }}: {{ $data['name'] ?: 'Unnamed section' }}
                            </span>
                            @if ($data['is_magis'] ?? false)
                                <span class="pill-draft">Magis</span>
                            @endif
                            @if ($flags)
                                <span class="flag flag-warn">Needs review</span>
                            @endif
                        </span>
                        <span class="block font-data text-[13px] text-slate-brand truncate mt-1">
                            {{ $data['full_name'] ?? '' }} &middot; Room {{ $data['room'] ?: '—' }}
                        </span>
                    </span>
                    <svg class="ledger-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </summary>

                <div class="ledger-body">
                    @foreach ($flags as $field => $message)
                        <p class="text-[13px] text-[#8a6200] bg-[#fdf6e3] border border-[#f0e0a8] rounded px-3 py-2 mb-3">
                            <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $field)) }}:</span> {{ $message }}
                        </p>
                    @endforeach

                    <form method="POST" action="{{ route('admin.roster.rows.update', $row) }}" class="flex flex-col gap-4">
                        @csrf @method('PATCH')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="field-label">Grade</label>
                                <select name="grade_level" class="field-input">
                                    @foreach (['G7', 'G8', 'G9', 'G10'] as $grade)
                                        <option value="{{ $grade }}" @selected(($data['grade_level'] ?? null) === $grade)>{{ $grade }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Room</label>
                                <input name="room" value="{{ $data['room'] ?? '' }}" class="field-input font-data">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="field-label">Registrar's full name</label>
                                <input name="full_name" value="{{ $data['full_name'] ?? '' }}" class="field-input">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="field-label">Short name (used by plantilla matching)</label>
                                <input name="name" value="{{ $data['name'] ?? '' }}" class="field-input font-data">
                            </div>
                            <div>
                                <label class="field-label">Moderator</label>
                                <input name="moderator_name" value="{{ $data['moderator_name'] ?? '' }}" class="field-input">
                            </div>
                            <div>
                                <label class="field-label">Teacher-partner</label>
                                <input name="teacher_partner_name" value="{{ $data['teacher_partner_name'] ?? '' }}" class="field-input">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="flex items-center gap-2 text-[13.5px]">
                                    <input type="hidden" name="is_magis" value="0">
                                    <input type="checkbox" name="is_magis" value="1" @checked($data['is_magis'] ?? false)>
                                    Magis class
                                </label>
                            </div>
                        </div>
                        <div><button type="submit" class="btn-secondary">Save section</button></div>
                    </form>
                </div>
            </details>
        @empty
            <div class="card p-10 text-center bg-parchment/60">
                <p class="font-display text-[22px] text-ink uppercase">Nothing to review</p>
                <a href="{{ route('admin.roster.create') }}" class="btn-primary mt-5">Upload roster</a>
            </div>
        @endforelse
    </div>
</x-app-layout>
