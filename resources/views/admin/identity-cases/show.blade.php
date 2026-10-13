<x-app-layout>
    <x-page-header eyebrow="Identity case #{{ $case->id }}" :title="$case->reason->label()">
        <x-slot:actions>
            <a href="{{ route('admin.identity-cases.index') }}" class="btn-secondary">All cases</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="card p-5 mb-5">
        <p class="font-semibold text-[14px] mb-2">{{ $description }}</p>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-[13.5px]">
            <dt class="text-slate-brand">Source</dt><dd>{{ $case->source_reference ?? $case->source_type }}</dd>
            <dt class="text-slate-brand">Written as</dt><dd>{{ $case->observed_name ?? '—' }}</dd>
            <dt class="text-slate-brand">Source department</dt><dd>{{ $case->observedDepartment?->name ?? 'Not stated' }}</dd>
            @if ($case->school_year)
                <dt class="text-slate-brand">School year</dt><dd>{{ $case->school_year }}</dd>
            @endif
            @if ($case->subjectTeacher)
                <dt class="text-slate-brand">Teacher in question</dt>
                <dd>{{ $case->subjectTeacher->full_name }} ({{ $case->subjectTeacher->department?->name ?? 'no department' }})</dd>
            @endif
            @foreach ($observations as $observation)
                @if ($observation->raw_name && $observation->raw_name !== $observation->observed_name)
                    <dt class="text-slate-brand">Originally read as</dt><dd>{{ $observation->raw_name }}</dd>
                @endif
            @endforeach
        </dl>
    </div>

    @if ($candidates->isNotEmpty())
        <div class="card overflow-hidden mb-5">
            <div class="px-5 py-4 border-b border-line font-bold text-[14px]">Existing teachers it may refer to</div>
            @foreach ($candidates as $candidate)
                <div class="px-5 py-3 border-b border-line last:border-0 text-[13.5px] flex flex-wrap gap-x-4">
                    <span class="font-semibold">{{ $candidate->full_name }}</span>
                    <span>{{ $candidate->department?->name ?? 'No department yet' }}</span>
                    <span class="text-slate-brand">{{ $candidate->verification_status->label() }}</span>
                    <span class="text-slate-brand">{{ $candidate->section_assignments_count }} section(s), {{ $candidate->moderator_assignments_count }} moderated</span>
                    @if ($case->subject_teacher_id && $case->isOpen())
                        <a class="text-electric font-semibold hover:underline" href="{{ route('admin.teachers.merge.preview', ['teacher' => $case->subject_teacher_id, 'survivor' => $candidate->id]) }}">Preview merge</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($case->chair_context)
        <div class="card p-5 mb-5">
            <p class="font-bold text-[14px] mb-2">Chair context</p>
            @foreach ($case->chair_context as $entry)
                <p class="text-[13.5px] mb-1"><span class="font-semibold">{{ $entry['name'] }}</span>
                    <span class="text-slate-brand">{{ \Illuminate\Support\Carbon::parse($entry['at'])->format('M j, Y g:i A') }}</span> &mdash; {{ $entry['note'] }}</p>
            @endforeach
        </div>
    @endif

    @if ($case->isOpen())
        <div class="card p-6 max-w-2xl">
            <form method="POST" action="{{ route('admin.identity-cases.resolve', $case) }}" class="flex flex-col gap-5">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <div>
                    <label for="decision" class="field-label">Decision</label>
                    <select id="decision" name="decision" class="field-input" required>
                        @foreach ($decisions as $value => $label)
                            <option value="{{ $value }}" @selected(old('decision') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('decision')" class="field-error" />
                </div>
                @if ($candidates->isNotEmpty())
                    <div>
                        <label for="teacher_id" class="field-label">Teacher</label>
                        <select id="teacher_id" name="teacher_id" class="field-input">
                            <option value="">Not applicable</option>
                            @foreach ($candidates as $candidate)
                                <option value="{{ $candidate->id }}" @selected(old('teacher_id') == $candidate->id)>{{ $candidate->full_name }} &mdash; {{ $candidate->department?->name ?? 'no department' }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('teacher_id')" class="field-error" />
                    </div>
                @endif
                <div>
                    <label for="note" class="field-label">Reason and evidence</label>
                    <textarea id="note" name="note" rows="3" class="field-input" required>{{ old('note') }}</textarea>
                    <p class="field-help">Say what you relied on: the Chair's confirmation, a directory, an appointment paper.</p>
                    <x-input-error :messages="$errors->get('note')" class="field-error" />
                </div>
                <div><button type="submit" class="btn-primary">Record decision</button></div>
            </form>
        </div>
    @else
        <div class="card p-5 text-[13.5px]">
            <p><span class="font-semibold">Decided:</span> {{ str_replace('_', ' ', $case->decision->value) }}
                @if ($case->resolutionTeacher) &mdash; {{ $case->resolutionTeacher->full_name }} @endif</p>
            <p class="text-slate-brand">{{ $case->resolvedBy?->name ?? 'system' }}, {{ $case->resolved_at?->format('M j, Y g:i A') }}</p>
            <p class="mt-2">{{ $case->resolution_note }}</p>
        </div>
    @endif
</x-app-layout>
