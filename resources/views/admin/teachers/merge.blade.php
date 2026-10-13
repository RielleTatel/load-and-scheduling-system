<x-app-layout>
    <x-page-header eyebrow="Teacher Directory" title="Merge a duplicate record" />

    <x-flash />

    <div class="card p-6 max-w-2xl mb-5">
        <p class="text-[13.5px] mb-4">Fold <span class="font-semibold">{{ $teacher->full_name }}</span>
            ({{ $teacher->department?->name ?? 'no department' }}, #{{ $teacher->id }}) into the record that should remain.
            The remaining record keeps its ID; this one is kept as history, marked merged.</p>
        <form method="GET" action="{{ route('admin.teachers.merge.preview', $teacher) }}" class="flex gap-2">
            <select name="survivor" class="field-input" required>
                <option value="">Choose the teacher to keep</option>
                @foreach ($options as $option)
                    <option value="{{ $option->id }}" @selected($survivor?->id === $option->id)>
                        {{ in_array($option->id, $suggested, true) ? '★ ' : '' }}{{ $option->full_name }} &mdash; {{ $option->department?->name ?? 'no department' }} (#{{ $option->id }})
                    </option>
                @endforeach
            </select>
            <button class="btn-secondary">Preview</button>
        </form>
    </div>

    @if ($preview)
        <div class="card p-6 max-w-2xl">
            <p class="font-bold text-[14px] mb-3">Keeping {{ $survivor->full_name }} (#{{ $survivor->id }})</p>
            <table class="w-full text-[13.5px] mb-4">
                @foreach ($preview['moves'] as $label => $count)
                    <tr><td class="py-1">{{ $label }} moving across</td><td class="text-right font-data">{{ $count }}</td></tr>
                @endforeach
                @foreach ($preview['collapses'] as $label => $count)
                    @if ($count)
                        <tr><td class="py-1 text-slate-brand">{{ $label }} (kept once)</td><td class="text-right font-data">{{ $count }}</td></tr>
                    @endif
                @endforeach
            </table>

            @if ($preview['blockers'])
                @foreach ($preview['blockers'] as $blocker)
                    <p class="text-rose-brand text-[13.5px] font-semibold mb-1">{{ $blocker }}</p>
                @endforeach
            @else
                <form method="POST" action="{{ route('admin.teachers.merge', $teacher) }}" class="flex flex-col gap-4"
                      data-confirm="Merge {{ $teacher->full_name }} into {{ $survivor->full_name }}? This cannot be split automatically." data-confirm-title="Merge teacher records" data-confirm-label="Merge">
                    @csrf
                    <input type="hidden" name="survivor_id" value="{{ $survivor->id }}">
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    @foreach ($preview['service_conflicts'] as $year => $values)
                        <div>
                            <label class="field-label">{{ $year }} service load differs</label>
                            <select name="service[{{ $year }}]" class="field-input" required>
                                <option value="">Choose the correct value</option>
                                <option value="survivor">{{ $values['survivor'] }} hours (kept record)</option>
                                <option value="redundant">{{ $values['redundant'] }} hours (duplicate)</option>
                            </select>
                        </div>
                    @endforeach
                    <textarea name="note" rows="2" class="field-input" placeholder="Why these are one person" required></textarea>
                    <x-input-error :messages="array_merge($errors->get('token'), $errors->get('service'))" class="field-error" />
                    <div><button class="btn-primary">Merge records</button></div>
                </form>
            @endif
        </div>
    @endif
</x-app-layout>
