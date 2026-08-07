<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department->name" title="Upload Plantilla" />

    <x-flash />

    @if (! $submission->status->isEditable())
        <div class="card p-6 border-l-4 border-navy">
            <p class="font-semibold">This dataset is {{ $submission->status->label() }}.</p>
            <p class="text-slate-brand text-sm mt-1">Editing is locked. You can't upload a new plantilla until it's returned for correction.</p>
        </div>
    @else
        <div class="card p-6 max-w-2xl"
             x-data="{ name: '' }">
            <p class="text-slate-brand text-sm mb-5">
                Upload your department's plantilla PDF. We'll extract what we can — you'll review and correct every row before anything is saved.
            </p>

            <form method="POST" action="{{ route('chair.plantilla.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                @csrf
                <label class="block border-2 border-dashed border-line rounded-card px-6 py-10 text-center cursor-pointer hover:border-electric transition"
                       :class="name && 'border-electric bg-electric/5'">
                    <input type="file" name="pdf" accept="application/pdf" class="sr-only"
                           @change="name = $event.target.files[0]?.name || ''">
                    <svg class="w-8 h-8 mx-auto text-slate-brand mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.9A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    <span class="font-semibold text-sm" x-text="name || 'Choose a PDF or drop it here'"></span>
                    <span class="block text-xs text-slate-brand mt-1">PDF up to 10 MB</span>
                </label>
                <x-input-error :messages="$errors->get('pdf')" class="field-error" />

                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">Extract &amp; review</button>
                    <a href="{{ route('chair.dashboard') }}" class="btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    @endif
</x-app-layout>
