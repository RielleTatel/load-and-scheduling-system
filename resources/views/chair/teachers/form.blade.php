@php $editing = $teacher->exists; @endphp

<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name" :title="$editing ? 'Edit teacher' : 'Add teacher'" />

    <div class="card p-6 max-w-xl">
        <form method="POST"
              action="{{ $editing ? route('chair.teachers.update', $teacher) : route('chair.teachers.store') }}"
              class="flex flex-col gap-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div>
                <label for="full_name" class="field-label">Full name</label>
                <input id="full_name" name="full_name" value="{{ old('full_name', $teacher->full_name) }}" class="field-input" required>
                <x-input-error :messages="$errors->get('full_name')" class="field-error" />
            </div>

            <div>
                <label for="employment_status" class="field-label">Employment status</label>
                <select id="employment_status" name="employment_status" class="field-input">
                    @foreach (\App\Enums\EmploymentStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(old('employment_status', $teacher->employment_status?->value) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('employment_status')" class="field-error" />
            </div>

            <div class="flex gap-2 pt-1">
                <button type="submit" class="btn-primary">{{ $editing ? 'Save changes' : 'Add teacher' }}</button>
                <a href="{{ route('chair.teachers.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
