@php $editing = $teacher->exists; @endphp

<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" :title="$editing ? 'Edit teacher' : 'Add teacher'" />

    <div class="card p-6 max-w-xl">
        <form method="POST"
              action="{{ $editing ? route('admin.teachers.update', $teacher) : route('admin.teachers.store') }}"
              class="flex flex-col gap-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div>
                <label for="full_name" class="field-label">Full name</label>
                <input id="full_name" name="full_name" value="{{ old('full_name', $teacher->full_name) }}" class="field-input" required>
                <p class="field-help">Honorifics and middle initials are ignored when matching, so "Bb. Cristie R. Delos Reyes" and "Cristie Delos Reyes" are the same person.</p>
                <x-input-error :messages="$errors->get('full_name')" class="field-error" />
            </div>

            <div>
                <label for="department_id" class="field-label">Department</label>
                <select id="department_id" name="department_id" class="field-input">
                    <option value="">Not known yet — awaiting their plantilla</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(old('department_id', $teacher->department_id) == $department->id)>
                            {{ $department->name }}
                        </option>
                    @endforeach
                </select>
                <p class="field-help">A teacher belongs to exactly one department. Leave blank for registrar-named staff whose sheet has not arrived.</p>
                <x-input-error :messages="$errors->get('department_id')" class="field-error" />
            </div>

            <div>
                <label for="employment_status" class="field-label">Employment status</label>
                <select id="employment_status" name="employment_status" class="field-input">
                    <option value="">Not set</option>
                    @foreach (\App\Enums\EmploymentStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(old('employment_status', $teacher->employment_status?->value) === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('employment_status')" class="field-error" />
            </div>

            <label class="flex items-center gap-2 text-[13.5px]">
                <input type="checkbox" name="confirm_distinct" value="1" @checked(old('confirm_distinct'))>
                This is a different person from any similarly named teacher
            </label>

            @if ($editing)
                <p class="field-help">Changing the name or department returns a verified teacher to pending verification.</p>
            @endif

            <div class="flex gap-2 pt-2">
                <button type="submit" class="btn-primary">{{ $editing ? 'Save changes' : 'Add teacher' }}</button>
                <a href="{{ route('admin.teachers.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>

    @if ($editing)
        <div class="card p-6 max-w-xl mt-5">
            <p class="font-bold text-[14px] mb-2">Identity verification &mdash; {{ $teacher->verification_status->label() }}</p>

            @if ($teacher->verified_at)
                <p class="text-[13.5px] mb-1">Last verified by {{ $teacher->verifiedBy?->name ?? 'a former Admin' }}, {{ $teacher->verified_at->format('M j, Y g:i A') }}
                    ({{ $teacher->verification_evidence['basis'] ?? '' }}): {{ $teacher->verification_evidence['note'] ?? '' }}</p>
                <p class="text-slate-brand text-[12.5px] mb-3">Attested as "{{ $teacher->verification_evidence['full_name'] ?? '' }}".</p>
            @endif

            @if ($teacher->chair_confirmed_at)
                <p class="text-[13.5px] mb-3">Chair confirmation: {{ $teacher->chairConfirmedBy?->name }}, {{ $teacher->chair_confirmed_at->format('M j, Y') }} &mdash; {{ $teacher->chair_confirmation_note }}</p>
            @else
                <p class="text-slate-brand text-[13.5px] mb-3">No Chair confirmation yet. Plantilla-based verification needs one.</p>
            @endif

            @foreach ($openCases as $case)
                <p class="text-[13.5px] mb-2"><a class="text-electric font-semibold hover:underline" href="{{ route('admin.identity-cases.show', $case) }}">Open identity case #{{ $case->id }}</a> &mdash; decide it before verifying.</p>
            @endforeach

            <p class="text-slate-brand text-[12.5px] mb-3">Sources: {{ $teacher->observations->pluck('source_reference')->filter()->unique()->implode('; ') ?: 'none recorded' }}</p>

            @if ($teacher->verification_status !== \App\Enums\TeacherVerification::Verified)
                <form method="POST" action="{{ route('admin.teachers.verify', $teacher) }}" class="flex flex-col gap-3">
                    @csrf
                    <select name="basis" class="field-input" required>
                        <option value="plantilla">Reviewed plantilla with the Chair's confirmation</option>
                        <option value="directory">Official teacher directory entry</option>
                        <option value="other">Other source (describe below)</option>
                    </select>
                    <textarea name="note" rows="2" class="field-input" placeholder="What you checked" required></textarea>
                    <x-input-error :messages="$errors->get('basis')" class="field-error" />
                    <div><button type="submit" class="btn-primary">Verify name and department</button></div>
                </form>
            @endif

            <p class="mt-4 text-[13.5px]">Duplicate record? <a class="text-electric font-semibold hover:underline" href="{{ route('admin.teachers.merge.preview', $teacher) }}">Merge it into another teacher</a></p>
        </div>
    @endif
</x-app-layout>
