<x-app-layout>
    <x-page-header eyebrow="System Administration" title="System Constants" />

    <x-flash />

    <p class="text-slate-brand text-sm mb-5 max-w-2xl">
        Load formulas read these values at runtime — nothing is hard-coded. Some are still unconfirmed with the registrar; update them here once finalized.
    </p>

    <div class="card divide-y divide-line">
        @foreach ($constants as $constant)
            <div class="p-5 flex flex-wrap items-center gap-4">
                <div class="flex-1 min-w-[240px]">
                    <p class="font-data font-semibold text-[14px]">{{ $constant->key }}</p>
                    @if ($constant->description)
                        <p class="text-[13px] text-slate-brand mt-1">
                            @if (str_contains($constant->description, 'UNCONFIRMED'))
                                <span class="flag flag-warn mr-1">UNCONFIRMED</span>
                            @endif
                            {{ \Illuminate\Support\Str::after($constant->description, 'UNCONFIRMED — ') }}
                        </p>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.constants.update', $constant) }}" class="flex items-center gap-2">
                    @csrf @method('PATCH')
                    <input name="value" value="{{ $constant->value }}"
                           class="field-input font-data w-32 text-center" aria-label="{{ $constant->key }} value">
                    <button type="submit" class="btn-secondary">Save</button>
                </form>
            </div>
        @endforeach
    </div>
</x-app-layout>
