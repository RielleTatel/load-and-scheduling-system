<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Import Class Moderator List" />

    <x-flash />

    <div class="card p-6 max-w-2xl">
        <p class="text-slate-brand text-sm mb-5">
            Upload the registrar's <span class="font-semibold">List of Class Moderators</span> PDF for
            {{ \App\Models\SystemConstant::get('current_school_year') }}. Nothing is saved until you review
            and confirm it &mdash; section short names in particular need your confirmation.
        </p>

        <form method="POST" action="{{ route('admin.roster.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
            @csrf
            <div>
                <label class="field-label">Roster PDF</label>
                <input type="file" name="pdf" accept="application/pdf" required class="field-input">
                @error('pdf') <p class="text-rose-brand text-[13px] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <button type="submit" class="btn-hero">Upload &amp; extract</button>
            </div>
        </form>
    </div>
</x-app-layout>
