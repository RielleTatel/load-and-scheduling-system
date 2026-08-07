@props(['label', 'value', 'note' => null, 'tone' => 'cobalt'])

@php
    $valueColor = match ($tone) {
        'amber' => 'text-amber-brand',
        'navy' => 'text-navy',
        'rose' => 'text-rose-brand',
        default => 'text-cobalt',
    };
@endphp

<div class="card p-5">
    <p class="text-[11px] uppercase tracking-[0.1em] text-slate-brand font-bold">{{ $label }}</p>
    <p class="font-display text-[40px] leading-none mt-1 {{ $valueColor }}">{{ $value }}</p>
    @if ($note)
        <p class="text-[13px] text-slate-brand mt-1">{{ $note }}</p>
    @endif
</div>
