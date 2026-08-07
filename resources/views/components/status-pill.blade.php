@props(['status'])

@php
    // Accepts a SubmissionStatus enum or a plain string value.
    $value = $status instanceof \App\Enums\SubmissionStatus ? $status->value : $status;
    $class = match ($value) {
        'submitted' => 'pill-submitted',
        'returned' => 'pill-returned',
        'locked' => 'pill-locked',
        default => 'pill-draft',
    };
@endphp

<span class="{{ $class }}">{{ ucfirst($value) }}</span>
