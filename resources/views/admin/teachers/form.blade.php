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

            <div class="flex gap-2 pt-2">
                <button type="submit" class="btn-primary">{{ $editing ? 'Save changes' : 'Add teacher' }}</button>
                <a href="{{ route('admin.teachers.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
