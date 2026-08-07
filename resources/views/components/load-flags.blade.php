@props(['flags' => []])

@php
    // Humanized labels + severity for each data-quality flag.
    $map = [
        'zero_sections'   => ['No sections', 'warn'],
        'no_service_load' => ['No service load', 'warn'],
        'below_full_load' => ['Under full load', 'warn'],
        'overloaded'      => ['Overloaded', 'crit'],
    ];
@endphp

@foreach ($flags as $flag)
    @php [$label, $tone] = $map[$flag] ?? [$flag, 'warn']; @endphp
    <span class="flag flag-{{ $tone }}">{{ $label }}</span>
@endforeach
