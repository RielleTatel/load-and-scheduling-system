<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name . ' · Review'" title="Review & Correct">
        <x-slot:actions>
            @if ($rows->isNotEmpty())
                <form method="POST" action="{{ route('chair.plantilla.confirm') }}"
                      onsubmit="return confirm('Import these rows into your department roster?')">
                    @csrf
                    <button type="submit" class="btn-hero">Confirm &amp; import</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <p class="text-slate-brand text-sm mb-5 max-w-2xl">
        Correct each row against your plantilla, then import. Grade/section columns don't survive
        PDF extraction, so fill those in as <span class="font-data">G7: Ignatius, Xavier; G9: Kostka</span>.
        Amber rows still need attention.
    </p>

    @php
        $statuses = \App\Enums\EmploymentStatus::cases();
        $fieldRow = fn ($row) => is_array($row->row_json) ? $row->row_json : [];
    @endphp

    <div class="flex flex-col gap-4">
        @forelse ($rows as $row)
            @php $data = $fieldRow($row); $flagged = $row->row_status === \App\Enums\ExtractionRowStatus::Flagged; @endphp
            <div @class(['card p-5', 'ring-1 ring-amber-brand/40 bg-amber-brand/[.03]' => $flagged])>
                <form method="POST" action="{{ route('chair.plantilla.rows.update', $row) }}" class="flex flex-col gap-4">
                    @csrf @method('PATCH')

                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="font-data text-xs text-slate-brand">Row {{ $loop->iteration }}</span>
                            @if ($flagged)<span class="flag flag-warn">Needs review</span>@endif
                        </div>
                        <button type="submit" form="delete-{{ $row->id }}" class="text-rose-brand text-[13px] font-semibold hover:underline">Remove</button>
                    </div>

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

                    <div>
                        <button type="submit" class="btn-secondary">Save row</button>
                    </div>
                </form>
                <form id="delete-{{ $row->id }}" method="POST" action="{{ route('chair.plantilla.rows.destroy', $row) }}" class="hidden">
                    @csrf @method('DELETE')
                </form>
            </div>
        @empty
            <div class="card p-8 text-center">
                <p class="font-semibold">No rows yet.</p>
                <p class="text-slate-brand text-sm mt-1">Upload a plantilla or add teachers manually below.</p>
            </div>
        @endforelse
    </div>

    {{-- Add a row manually --}}
    <div class="card p-5 mt-4 border-dashed">
        <h2 class="font-bold text-sm mb-3">Add a teacher manually</h2>
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
                <button type="submit" class="btn-ghost">Add row</button>
            </div>
        </form>
    </div>
</x-app-layout>
