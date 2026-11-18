{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
{{-- Emailed sign-out link page (fork). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Besuch beenden') }}</title>
    @vite(['resources/css/app.css'])
    <x-brand-stylesheet />
    <style>body { font-family: ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", system-ui, sans-serif; }</style>
</head>
<body class="min-h-dvh bg-base-200 text-base-content antialiased">
    <div class="brand-on-primary mx-auto flex min-h-dvh max-w-lg flex-col px-6 py-8">
        @if ($logo)
            <img src="{{ asset($logo) }}" alt="{{ config('branding.name') }}" class="h-14 w-auto self-start object-contain">
        @endif

        <main class="mt-8 flex flex-1 flex-col justify-center gap-6 rounded-[2rem] bg-base-100 p-8 text-center shadow-sm">
            @if ($state === 'confirm')
                <h1 class="text-3xl font-bold">{{ __('Besuch beenden?') }}</h1>
                <p class="text-lg">{{ __(':name, tippen Sie auf die Schaltfläche, um sich bei :site abzumelden.', ['name' => $firstName, 'site' => $siteName]) }}</p>
                <form method="POST" action="{{ route('leave.store', $token) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary h-16 w-full rounded-full text-xl font-bold">{{ __('Jetzt abmelden') }}</button>
                </form>
            @elseif ($state === 'done')
                <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-primary text-primary-content" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="size-10"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                </span>
                <h1 class="text-3xl font-bold">{{ __('Auf Wiedersehen, :name!', ['name' => $firstName]) }}</h1>
                <p class="text-lg">{{ $confirmation ?: __('Sie sind abgemeldet. Danke für Ihren Besuch.') }}</p>
            @else
                <h1 class="text-2xl font-bold">{{ __('Dieser Abmeldelink ist nicht mehr gültig. Bitte melden Sie sich am Empfang ab.') }}</h1>
                <p>{{ __('Vielleicht sind Sie bereits abgemeldet.') }}</p>
            @endif
        </main>
    </div>
</body>
</html>
