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
    </body>
</html>
