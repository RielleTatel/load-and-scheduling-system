@if (session('status'))
    <div class="mb-5 flex items-center gap-2 rounded-card border border-jade/30 bg-jade/10 text-jade px-4 py-3 text-sm font-semibold">
        <span class="w-2 h-2 rounded-full bg-jade"></span>
        {{ session('status') }}
    </div>
@endif

@if (session('warning'))
    <div class="mb-5 flex items-center gap-2 rounded-card border border-amber-brand/30 bg-amber-brand/10 text-[#8a6200] px-4 py-3 text-sm font-semibold">
        <span class="w-2 h-2 rounded-full bg-amber-brand"></span>
        {{ session('warning') }}
    </div>
@endif
