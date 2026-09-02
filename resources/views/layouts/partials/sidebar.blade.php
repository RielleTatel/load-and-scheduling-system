@php
    $user = auth()->user();

    // Nav items per role. Items whose route doesn't exist yet are skipped,
    // so they appear automatically as later milestones add the routes.
    $groups = $user->isAdmin()
        ? [
            'System' => [
                ['label' => 'Dashboard', 'route' => 'admin.dashboard'],
                ['label' => 'Users', 'route' => 'admin.users.index'],
                ['label' => 'System constants', 'route' => 'admin.constants.index'],
                ['label' => 'Assignment roles', 'route' => 'admin.roles.index'],
                ['label' => 'Audit log', 'route' => 'admin.audit.index'],
            ],
            // Registrar reference data — school-wide, re-issued each year, and
            // what every department's plantilla import is validated against.
            'Reference data' => [
                ['label' => 'Section roster', 'route' => 'admin.sections.index'],
                ['label' => 'Teacher directory', 'route' => 'admin.teachers.index'],
            ],
        ]
        : [
            'Department' => [
                ['label' => 'Dashboard', 'route' => 'chair.dashboard'],
                ['label' => 'Upload plantilla', 'route' => 'chair.plantilla.create'],
                ['label' => 'Review & correct', 'route' => 'chair.plantilla.review'],
                ['label' => 'Teacher roster', 'route' => 'chair.teachers.index'],
                ['label' => 'Section assignments', 'route' => 'chair.assignments.index'],
                ['label' => 'Submit for review', 'route' => 'chair.submission.show'],
            ],
        ];

    $subtitle = $user->isAdmin() ? 'System Admin' : ($user->department?->name ?? 'Department');
    $initials = collect(explode(' ', $user->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
@endphp

<div class="flex items-center gap-3 px-5 pt-5 pb-6">
    <span class="w-9 h-9 rounded-[9px] bg-canary text-navy grid place-items-center font-display text-base">J</span>
    <span class="leading-tight">
        <span class="font-display uppercase text-[13px] tracking-wide block">JHS Load</span>
        <span class="text-[10.5px] uppercase tracking-[0.08em] text-white/60">{{ $subtitle }}</span>
    </span>
</div>

<nav class="flex-1 px-3 space-y-1 overflow-y-auto">
    @foreach ($groups as $groupLabel => $items)
        <p class="text-[10.5px] uppercase tracking-[0.12em] text-white/45 font-bold px-3 pt-3 pb-1.5">{{ $groupLabel }}</p>
        @foreach ($items as $item)
            @continue(! Route::has($item['route']))
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ route($item['route']) }}"
               @class([
                   'relative flex items-center gap-3 px-3 py-2.5 rounded-[9px] text-[13.5px] transition',
                   'bg-white/[.08] text-white font-semibold' => $active,
                   'text-white/80 hover:bg-navy-800 hover:text-white font-medium' => ! $active,
               ])>
                @if ($active)
                    <span class="absolute -left-3 top-1.5 bottom-1.5 w-1 bg-canary rounded-r"></span>
                @endif
                <span @class(['w-4 h-4 rounded-[5px] flex-none', 'bg-canary' => $active, 'bg-white/25' => ! $active])></span>
                {{ $item['label'] }}
            </a>
        @endforeach
    @endforeach
</nav>

<div class="p-3 mt-auto">
    <div class="flex items-center gap-3 p-2.5 rounded-[11px] bg-navy-800">
        <span class="w-8 h-8 rounded-full bg-electric text-white grid place-items-center font-bold text-[13px] flex-none">{{ $initials }}</span>
        <span class="min-w-0 flex-1">
            <span class="text-[13px] font-semibold block truncate">{{ $user->name }}</span>
            <span class="text-[11px] text-white/60 block truncate">{{ $user->role->label() }}</span>
        </span>
    </div>
    <div class="flex gap-1 mt-1.5">
        <a href="{{ route('profile.edit') }}" class="flex-1 text-center text-[12px] text-white/70 hover:text-white py-1.5 rounded-lg hover:bg-navy-800 transition">Profile</a>
        <form method="POST" action="{{ route('logout') }}" class="flex-1">
            @csrf
            <button type="submit" class="w-full text-[12px] text-white/70 hover:text-white py-1.5 rounded-lg hover:bg-navy-800 transition">Sign out</button>
        </form>
    </div>
</div>
