<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Identity cases">
        <x-slot:actions>
            <a href="{{ route('admin.teachers.index') }}" class="btn-secondary">Teacher Directory</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <p class="text-slate-brand text-sm mb-5 max-w-3xl">
        A name that might be an existing teacher, might be two people, or disagrees about a department waits here for
        an Admin. Imports that raised a case stay staged until it is decided; similar spelling alone never joins two
        records.
    </p>

    <div class="card overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-line font-bold text-[14px]">Open ({{ $open->count() }})</div>
        @forelse ($open as $case)
            <a href="{{ route('admin.identity-cases.show', $case) }}" class="block px-5 py-3.5 border-b border-line last:border-0 hover:bg-mist/60">
                <span class="flag flag-warn">{{ $case->reason->label() }}</span>
                <span class="text-[13.5px] ml-2">{{ $describe($case) }}</span>
                <span class="block text-slate-brand text-[12.5px] mt-1">{{ $case->source_reference }}</span>
            </a>
        @empty
            <p class="px-5 py-4 text-slate-brand text-[13.5px]">Nothing is waiting for a decision.</p>
        @endforelse
    </div>

    @if ($resolved->isNotEmpty())
        <div class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-line font-bold text-[14px]">Recently decided</div>
            @foreach ($resolved as $case)
                <a href="{{ route('admin.identity-cases.show', $case) }}" class="block px-5 py-3 border-b border-line last:border-0 hover:bg-mist/60 text-[13.5px]">
                    #{{ $case->id }} &middot; {{ $case->observed_name ?? $case->subjectTeacher?->full_name }}
                    &middot; {{ str_replace('_', ' ', $case->decision?->value) }}
                    <span class="text-slate-brand">&middot; {{ $case->resolvedBy?->name ?? 'system' }}, {{ $case->resolved_at?->format('M j, Y g:i A') }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-app-layout>
