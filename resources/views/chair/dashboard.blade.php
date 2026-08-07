<x-app-layout>
    <x-page-header :eyebrow="auth()->user()->department?->name ?? 'Department'" title="Department Dashboard" />

    <div class="card p-6">
        <p class="text-slate-brand">Welcome, {{ auth()->user()->name }}. Your submission status, teacher roster, and data-quality flags will appear here once you import your plantilla.</p>
    </div>
</x-app-layout>
