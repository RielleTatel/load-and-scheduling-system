<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'JHS Load & Scheduling') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen lg:flex bg-mist" x-data="{ open: false }">
            {{-- Mobile top bar --}}
            <div class="lg:hidden flex items-center justify-between bg-navy text-white px-4 py-3">
                <span class="font-display uppercase text-sm tracking-wide">JHS Load</span>
                <button @click="open = !open" class="p-2 -mr-2" aria-label="Toggle navigation">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>

            {{-- Sidebar --}}
            <aside
                class="fixed inset-y-0 left-0 z-40 w-60 bg-navy text-white flex flex-col transition-transform lg:translate-x-0 lg:static lg:z-auto"
                :class="open ? 'translate-x-0' : '-translate-x-full'">
                @include('layouts.partials.sidebar')
            </aside>

            {{-- Backdrop (mobile) --}}
            <div x-show="open" @click="open = false" x-cloak
                 class="fixed inset-0 z-30 bg-navy/50 lg:hidden"></div>

            {{-- Main --}}
            <main class="flex-1 min-w-0">
                <div class="max-w-[1200px] mx-auto px-6 lg:px-8 py-8">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <dialog id="app-confirm-dialog" class="app-confirm-dialog" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">
            <form method="dialog" class="app-confirm-card">
                <button type="button" data-confirm-dismiss class="app-confirm-close btn-ghost" aria-label="Close confirmation">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M6 18 18 6"/></svg>
                </button>
                <div class="app-confirm-content">
                <div class="app-confirm-icon" aria-hidden="true">
                    <svg data-confirm-icon="calendar" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18M8 15h.01M12 15h.01M16 15h.01M8 18h.01M12 18h.01"/>
                    </svg>
                    <svg data-confirm-icon="publish" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
                    </svg>
                    <svg data-confirm-icon="import" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M12 11v7m-3-3 3 3 3-3"/>
                    </svg>
                    <svg data-confirm-icon="submit" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/>
                    </svg>
                    <svg data-confirm-icon="stop" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"/><rect x="8" y="8" width="8" height="8" rx="1"/>
                    </svg>
                    <svg data-confirm-icon="remove" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/>
                    </svg>
                    <svg data-confirm-icon="warning" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.1 3.6a2.2 2.2 0 0 1 3.8 0l8.1 14A2.2 2.2 0 0 1 20.1 21H3.9A2.2 2.2 0 0 1 2 17.6zM12 8v5M12 17h.01"/>
                    </svg>
                </div>
                <h2 id="app-confirm-title" class="app-confirm-title">Please confirm</h2>
                <span class="app-confirm-tick" aria-hidden="true"></span>
                <p id="app-confirm-message" class="app-confirm-message"></p>
                </div>
                <div class="app-confirm-actions">
                    <button type="button" data-confirm-cancel class="btn-secondary">Cancel</button>
                    <button type="button" data-confirm-accept class="btn-primary">Continue</button>
                </div>
            </form>
        </dialog>
    </body>
</html>
