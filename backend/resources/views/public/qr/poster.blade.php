{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
{{-- Printable A4 mobile check-in poster (fork). Organisation colours from brand.css. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Aushang: :site', ['site' => $siteName]) }}</title>
    @vite(['resources/css/app.css'])
    <x-brand-stylesheet />
    <style>
        @page { size: A4 portrait; margin: 0; }
        html, body { background: #fff !important; }
        body { font-family: ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 18mm 18mm 14mm; display: flex; flex-direction: column; box-sizing: border-box; }
        .band { height: 8mm; border-radius: 4mm; background: var(--color-primary); }
        .qr { width: 120mm; height: 120mm; margin: 0 auto; padding: 5mm; border: 1.5mm solid var(--color-primary); border-radius: 8mm; background: #fff; }
        .qr svg { width: 100%; height: 100%; }
        @media screen { body { background: #e5e7eb !important; } .sheet { background: #fff; margin: 1.5rem auto; box-shadow: 0 4px 24px rgb(0 0 0 / .12); } }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="text-base-content antialiased">
    <div class="no-print mx-auto flex max-w-[210mm] items-center justify-between gap-4 px-4 pt-6">
        <p class="text-sm">@unless ($enabled)<strong class="text-error">{{ __('Ausgeschaltet: der Link funktioniert gerade nicht.') }}</strong>@endunless</p>
        <button type="button" onclick="window.print()" class="btn btn-primary rounded-full px-6">{{ __('Drucken') }}</button>
    </div>

    <main class="sheet">
        <header class="flex items-center justify-between gap-6">
            @if ($logo)
                <img src="{{ asset($logo) }}" alt="{{ config('branding.name') }}" class="h-20 w-auto max-w-[60%] object-contain">
            @else
                <span class="text-4xl font-bold">{{ config('branding.name') }}</span>
            @endif
            <span class="text-right text-lg font-semibold">{{ $siteName }}</span>
        </header>

        <h1 class="mt-14 text-6xl font-bold leading-tight">{{ $heading }}</h1>
        <p class="mt-4 text-3xl font-semibold">{{ __('Mit dem Handy anmelden') }}</p>

        <div class="qr mt-12">{!! $qr !!}</div>

        <ol class="mx-auto mt-10 grid max-w-[150mm] gap-3 text-2xl">
            <li><strong>1.</strong> {{ __('Öffnen Sie die Kamera Ihres Handys.') }}</li>
            <li><strong>2.</strong> {{ __('Richten Sie sie auf den QR-Code und tippen Sie auf den Link.') }}</li>
            <li><strong>3.</strong> {{ __('Folgen Sie den Schritten, um sich anzumelden.') }}</li>
        </ol>

        <p class="mt-auto pt-10 text-center text-sm break-all">{{ $url }}</p>
        <div class="band mt-4"></div>
    </main>
</body>
</html>
