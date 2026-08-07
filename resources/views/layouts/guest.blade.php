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
        <div class="min-h-screen flex flex-col justify-center items-center px-4 py-10"
             style="background: radial-gradient(120% 130% at 50% 0%, #2A2FE0 0%, #1E22C4 38%, #0B0B45 82%, #05052A 100%);">
            <div class="anuncio-frame bg-white w-full sm:max-w-md p-8 sm:p-10 shadow-[0_30px_60px_rgba(5,5,42,.4)]">
                {{ $slot }}
            </div>
            <p class="mt-6 text-xs text-white/60 tracking-wide">Ateneo de Zamboanga University · Junior High School</p>
        </div>
    </body>
</html>
