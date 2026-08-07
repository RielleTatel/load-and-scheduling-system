@php $editing = $role->exists; @endphp

<x-app-layout>
    <x-page-header eyebrow="System Administration" :title="$editing ? 'Edit role' : 'Add role'" />

    <div class="card p-6 max-w-xl"
         x-data="{ honorarium: {{ old('is_honorarium', $role->is_honorarium) ? 'true' : 'false' }} }">
        <form method="POST"
              action="{{ $editing ? route('admin.roles.update', $role) : route('admin.roles.store') }}"
              class="flex flex-col gap-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div>
                <label for="name" class="field-label">Role name</label>
                <input id="name" name="name" value="{{ old('name', $role->name) }}" class="field-input" required>
                <x-input-error :messages="$errors->get('name')" class="field-error" />
            </div>

            <label class="inline-flex items-center gap-2.5 text-sm">
                <input type="checkbox" name="is_honorarium" value="1" x-model="honorarium"
                       @checked(old('is_honorarium', $role->is_honorarium))
                       class="rounded border-line text-cobalt focus:ring-electric">
                <span class="font-semibold">Honorarium-only role</span>
            </label>
            <p class="field-help -mt-3">Honorarium roles are compensated separately and excluded from load and overload math.</p>

            <div x-show="!honorarium" x-cloak>
                <label for="equivalent_hours" class="field-label">Equivalent hours</label>
                <input id="equivalent_hours" name="equivalent_hours" type="number" step="0.5" min="0"
                       value="{{ old('equivalent_hours', $role->equivalent_hours) }}" class="field-input w-40">
                <x-input-error :messages="$errors->get('equivalent_hours')" class="field-error" />
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit" class="btn-primary">{{ $editing ? 'Save changes' : 'Create role' }}</button>
                <a href="{{ route('admin.roles.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
