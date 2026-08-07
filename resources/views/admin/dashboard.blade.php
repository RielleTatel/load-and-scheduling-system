<x-app-layout>
    <x-page-header eyebrow="System Administration" title="Dashboard" />

    <div class="card p-6">
        <p class="text-slate-brand">Welcome, {{ auth()->user()->name }}. Account management, system constants, the assignment-role lookup, and the audit log will appear here.</p>
    </div>
</x-app-layout>
