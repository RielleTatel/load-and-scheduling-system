@php $editing = $section->exists; @endphp

<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" :title="$editing ? 'Edit section' : 'Add section'" />

    <div class="card p-6 max-w-2xl">
        <form method="POST"
              action="{{ $editing ? route('admin.sections.update', $section) : route('admin.sections.store') }}"
              class="flex flex-col gap-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid sm:grid-cols-3 gap-4">
                <div>
                    <label for="grade_level" class="field-label">Grade</label>
                    <select id="grade_level" name="grade_level" class="field-input" required>
                        @foreach (['G7', 'G8', 'G9', 'G10'] as $grade)
                            <option value="{{ $grade }}" @selected(old('grade_level', $section->grade_level?->value) === $grade)>
                                Grade {{ substr($grade, 1) }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('grade_level')" class="field-error" />
                </div>

                <div class="sm:col-span-2">
                    <label for="name" class="field-label">Short name</label>
                    <input id="name" name="name" value="{{ old('name', $section->name) }}" class="field-input" required>
                    <p class="field-help">As the plantillas write it — "Xavier", "Ignatius of Loyola". Must be unique across every grade.</p>
                    <x-input-error :messages="$errors->get('name')" class="field-error" />
                </div>
            </div>

            <div>
                <label for="full_name" class="field-label">Official name</label>
                <input id="full_name" name="full_name" value="{{ old('full_name', $section->full_name) }}" class="field-input">
                <p class="field-help">As the registrar writes it — "Saint Francis Xavier".</p>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label for="room" class="field-label">Room</label>
                    <input id="room" name="room" value="{{ old('room', $section->room) }}" class="field-input w-32">
                </div>
                <label class="inline-flex items-center gap-2.5 text-sm self-end pb-2.5">
                    <input type="checkbox" name="is_magis" value="1" @checked(old('is_magis', $section->is_magis))
                           class="rounded border-line text-cobalt focus:ring-electric">
                    <span class="font-semibold">Magis (Honor's) class</span>
                </label>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label for="moderator_name" class="field-label">Class moderator</label>
                    <input id="moderator_name" name="moderator_name" value="{{ old('moderator_name', $section->moderator_name) }}" class="field-input">
                    <p class="field-help">From the registrar's list. Assigned automatically when this teacher's plantilla is imported.</p>
                </div>
                <div>
                    <label for="teacher_partner_name" class="field-label">Teacher-partner</label>
                    <input id="teacher_partner_name" name="teacher_partner_name" value="{{ old('teacher_partner_name', $section->teacher_partner_name) }}" class="field-input">
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit" class="btn-primary">{{ $editing ? 'Save changes' : 'Add section' }}</button>
                <a href="{{ route('admin.sections.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
