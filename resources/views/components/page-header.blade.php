@props(['eyebrow' => null, 'title'])

<div class="flex flex-wrap items-start justify-between gap-4 mb-7">
    <div class="title-rule">
        @if ($eyebrow)
            <p class="eyebrow mb-2">{{ $eyebrow }}</p>
        @endif
        <h1 class="text-[28px]">{{ $title }}</h1>
        <span class="tick"></span>
    </div>
    @if (isset($actions))
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endif
</div>
