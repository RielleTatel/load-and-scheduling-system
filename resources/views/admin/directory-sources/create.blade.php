<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Record a teacher directory" />

    <div class="card p-6 max-w-2xl">
        <p class="text-slate-brand text-[13.5px] mb-5">
            Exact names link to existing teachers and add to their evidence. A different spelling opens an identity case.
            Only a list you declare complete for its school year and scope can flag someone as missing, and a missing
            teacher keeps their record and loads while you investigate.
        </p>
        <form method="POST" action="{{ route('admin.directory-sources.store') }}" class="flex flex-col gap-5">
            @csrf
            <div>
                <label for="reference" class="field-label">Source</label>
                <input id="reference" name="reference" value="{{ old('reference') }}" class="field-input" placeholder="e.g. HR teacher directory, memo of 12 Oct 2026" required>
                <x-input-error :messages="$errors->get('reference')" class="field-error" />
            </div>
            <div>
                <label for="school_year" class="field-label">School year</label>
                <input id="school_year" name="school_year" value="{{ old('school_year', $schoolYear) }}" class="field-input" required>
                <x-input-error :messages="$errors->get('school_year')" class="field-error" />
            </div>
            <div>
                <label for="completeness" class="field-label">Coverage</label>
                <select id="completeness" name="completeness" class="field-input">
                    <option value="partial">Partial or unknown &mdash; never flags absences</option>
                    <option value="complete" @selected(old('completeness') === 'complete')>Complete for the scope below</option>
                </select>
            </div>
            <div>
                <label for="department_id" class="field-label">Scope</label>
                <select id="department_id" name="department_id" class="field-input">
                    <option value="">Whole school</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="names" class="field-label">Names, one per line</label>
                <textarea id="names" name="names" rows="10" class="field-input" required>{{ old('names') }}</textarea>
                <x-input-error :messages="$errors->get('names')" class="field-error" />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Record directory</button>
                <a href="{{ route('admin.teachers.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
