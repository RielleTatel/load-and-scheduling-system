@props(['route', 'section', 'teachers', 'placeholder' => 'Assign'])

<form method="POST" action="{{ $route }}" class="flex items-center gap-1.5">
    @csrf
    <input type="hidden" name="section_id" value="{{ $section->id }}">
    <select name="teacher_id" required
            class="field-input py-1.5 text-[13px] max-w-[190px]">
        <option value="">{{ $placeholder }}…</option>
        @foreach ($teachers as $teacher)
            <option value="{{ $teacher->id }}">{{ $teacher->full_name }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn-ghost px-2.5 py-1.5 text-[13px]">Set</button>
</form>
