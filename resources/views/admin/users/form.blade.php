@php $editing = $user->exists; @endphp

<x-app-layout>
    <x-page-header eyebrow="System Administration" :title="$editing ? 'Edit user' : 'Add user'" />

    <div class="card p-6 max-w-2xl"
         x-data="{ role: '{{ old('role', $user->role?->value ?? 'department_chair') }}' }">
        <form method="POST"
              action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}"
              class="flex flex-col gap-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div>
                <label for="name" class="field-label">Full name</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" class="field-input" required>
                <x-input-error :messages="$errors->get('name')" class="field-error" />
            </div>

            <div>
                <label for="email" class="field-label">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="field-input" required>
                <x-input-error :messages="$errors->get('email')" class="field-error" />
            </div>

            <div>
                <label for="role" class="field-label">Role</label>
                <select id="role" name="role" x-model="role" class="field-input">
                    @foreach (\App\Enums\UserRole::cases() as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('role')" class="field-error" />
            </div>

            <div x-show="role === 'department_chair'" x-cloak>
                <label for="department_id" class="field-label">Department</label>
                <select id="department_id" name="department_id" class="field-input">
                    <option value="">Select a department</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->id }}" @selected(old('department_id', $user->department_id) == $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('department_id')" class="field-error" />
                <p class="field-help">Chairs are scoped to one department and can only see their own data.</p>
            </div>

            <div>
                <label for="password" class="field-label">
                    Password @if ($editing) <span class="text-slate-brand font-normal">— leave blank to keep current</span> @endif
                </label>
                <input id="password" type="password" name="password" class="field-input" autocomplete="new-password" @unless($editing) required @endunless>
                <x-input-error :messages="$errors->get('password')" class="field-error" />
            </div>

            <div class="flex items-center justify-between gap-3 pt-2">
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">{{ $editing ? 'Save changes' : 'Create user' }}</button>
                    <a href="{{ route('admin.users.index') }}" class="btn-ghost">Cancel</a>
                </div>
            </div>
        </form>

        @if ($editing)
            <form method="POST" action="{{ route('admin.users.toggle', $user) }}"
                  class="mt-5 pt-5 border-t border-line">
                @csrf @method('PATCH')
                @if ($user->is_active)
                    <button type="submit" class="btn-danger">Deactivate account</button>
                    <p class="field-help mt-2">Suspends access without deleting history. You can reactivate later.</p>
                @else
                    <button type="submit" class="btn-secondary">Reactivate account</button>
                    <p class="field-help mt-2">This account is currently inactive and cannot sign in.</p>
                @endif
            </form>
        @endif
    </div>
</x-app-layout>
