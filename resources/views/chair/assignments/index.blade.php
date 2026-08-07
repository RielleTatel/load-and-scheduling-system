<x-app-layout>
    <x-page-header :eyebrow="$department->name" title="Section Assignments" />

    <x-flash />
    @error('teacher_id')
        <div class="mb-5 flex items-center gap-2 rounded-card border border-rose-brand/30 bg-rose-brand/10 text-rose-brand px-4 py-3 text-sm font-semibold">
            <span class="w-2 h-2 rounded-full bg-rose-brand"></span>{{ $message }}
        </div>
    @enderror

    <p class="text-slate-brand text-sm mb-5 max-w-2xl">
        Assign which teacher covers each section — load data only, no periods or rooms. One subject teacher per section; one class moderator per section across the whole school.
    </p>

    @php $editable = $submission->status->isEditable(); @endphp

    <div x-data="{ grade: '{{ $grades[0]->value }}' }">
        {{-- Grade tabs --}}
        <div class="flex gap-1 mb-4 border-b border-line">
            @foreach ($grades as $g)
                <button @click="grade = '{{ $g->value }}'"
                        :class="grade === '{{ $g->value }}' ? 'text-cobalt border-cobalt' : 'text-slate-brand border-transparent hover:text-ink'"
                        class="px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition">{{ $g->value }}</button>
            @endforeach
        </div>

        @foreach ($grades as $g)
            <div x-show="grade === '{{ $g->value }}'" x-cloak class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse min-w-[720px]">
                        <thead>
                            <tr class="bg-mist border-b border-line">
                                <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Section</th>
                                <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">{{ $department->name }} teacher</th>
                                <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Class moderator</th>
                                @if ($department->has_honors_class)
                                    <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Honor's class</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($sections[$g->value] ?? []) as $section)
                                <tr class="border-b border-line last:border-0">
                                    <td class="px-4 py-3 font-semibold text-[14px] whitespace-nowrap">{{ $section->name }}</td>

                                    {{-- Subject teacher --}}
                                    <td class="px-4 py-3">
                                        @php $subject = $subjectBySection[$section->id] ?? null; @endphp
                                        @if ($subject)
                                            <div class="flex items-center gap-2">
                                                <span class="text-[13.5px]">{{ $subject->teacher->full_name }}</span>
                                                @if ($editable)
                                                    <form method="POST" action="{{ route('chair.assignments.destroy') }}">
                                                        @csrf @method('DELETE')
                                                        <input type="hidden" name="assignment_id" value="{{ $subject->id }}">
                                                        <button class="text-rose-brand text-xs hover:underline">clear</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @elseif ($editable)
                                            <x-assign-form :route="route('chair.assignments.store')" :section="$section" :teachers="$teachers" placeholder="Assign teacher" />
                                        @else
                                            <span class="text-slate-brand text-[13px]">Unassigned</span>
                                        @endif
                                    </td>

                                    {{-- Class moderator --}}
                                    <td class="px-4 py-3">
                                        @php $mod = $moderatorBySection[$section->id] ?? null; @endphp
                                        @if ($mod)
                                            <div class="flex items-center gap-2">
                                                <span class="text-[13.5px]">{{ $mod->teacher->full_name }}</span>
                                                @if ($editable && $teachers->contains('id', $mod->teacher_id))
                                                    <form method="POST" action="{{ route('chair.moderators.destroy') }}">
                                                        @csrf @method('DELETE')
                                                        <input type="hidden" name="assignment_id" value="{{ $mod->id }}">
                                                        <button class="text-rose-brand text-xs hover:underline">clear</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @elseif ($editable)
                                            <x-assign-form :route="route('chair.moderators.store')" :section="$section" :teachers="$teachers" placeholder="Assign moderator" />
                                        @else
                                            <span class="text-slate-brand text-[13px]">—</span>
                                        @endif
                                    </td>

                                    {{-- Honor's class --}}
                                    @if ($department->has_honors_class)
                                        <td class="px-4 py-3">
                                            @php $honors = $honorsBySection[$section->id] ?? null; @endphp
                                            @if ($honors)
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[13.5px]">{{ $honors->teacher->full_name }}</span>
                                                    @if ($editable)
                                                        <form method="POST" action="{{ route('chair.honors.destroy') }}">
                                                            @csrf @method('DELETE')
                                                            <input type="hidden" name="assignment_id" value="{{ $honors->id }}">
                                                            <button class="text-rose-brand text-xs hover:underline">clear</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            @elseif ($editable)
                                                <x-assign-form :route="route('chair.honors.store')" :section="$section" :teachers="$teachers" placeholder="Assign HC" />
                                            @else
                                                <span class="text-slate-brand text-[13px]">—</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>
