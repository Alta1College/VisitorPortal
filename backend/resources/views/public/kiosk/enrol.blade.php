{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Kiosk einrichten') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-brand-stylesheet />
</head>
<body class="flex min-h-screen items-center justify-center bg-base-200 p-6 text-base-content antialiased">
    <main class="card w-full max-w-md bg-base-100 shadow-sm">
        <form method="POST" action="{{ route('kiosk.enrol.store') }}" class="card-body gap-4">
            @csrf
            <h1 class="card-title text-2xl">{{ __('Kiosk einrichten') }}</h1>
            <p class="text-base-content/70">{{ __('Geben Sie den Einrichtungscode aus dem Verwaltungsbereich ein (Kiosks → Einrichtungscode).') }}</p>
            <label class="form-control">
                <span class="label-text mb-1">{{ __('Einrichtungscode') }}</span>
                <input name="code" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false"
                       class="input input-bordered input-lg w-full font-mono tracking-widest uppercase" placeholder="ABCD-2345" required autofocus>
            </label>
            @error('code')<p class="text-error">{{ $message }}</p>@enderror
            <button type="submit" class="btn btn-primary btn-lg">{{ __('Kiosk verbinden') }}</button>
        </form>
    </main>
</body>
</html>
