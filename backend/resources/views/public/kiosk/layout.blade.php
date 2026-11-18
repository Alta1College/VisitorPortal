{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
{{-- Kiosk layout (fork). Touch-first, always the light theme, organisation colours from brand.css. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $siteName ?? config('branding.name') }}</title>
    @includeIf('partials.favicons')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-brand-stylesheet />
    <style>
        /* Kiosk ergonomics: no text selection, no callouts, large tap targets. Sized for tablets
           (iPad portrait 768–834 px wide, landscape up to 1366 px). */
        html, body { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; overscroll-behavior: none; -webkit-tap-highlight-color: transparent; }
        input, textarea { -webkit-user-select: text; user-select: text; }
        .kiosk-tap { min-height: 4rem; padding-inline: 2rem; border-radius: 9999px; font-size: 1.25rem; font-weight: 700; }
        .kiosk-pill { height: auto; min-height: 4.5rem; min-width: 15rem; padding: 0.75rem 2.5rem; justify-content: flex-start;
                      border: 0; border-radius: 9999px; font-size: clamp(1.375rem, 3vw, 1.875rem); font-weight: 700;
                      box-shadow: 0 2px 0 rgb(0 0 0 / 0.06); transition: transform 80ms ease; }
        .kiosk-pill:active, .kiosk-tap:active { transform: scale(0.97); }
        .kiosk-field { height: 4rem; border-radius: 1rem; font-size: 1.25rem; }
        .kiosk-title { font-size: clamp(1.75rem, 4vw, 2.5rem); font-weight: 700; line-height: 1.1; letter-spacing: -0.02em; }
        .kiosk-hero { font-size: clamp(2.125rem, 5.4vw, 3.5rem); font-weight: 700; line-height: 1.15; letter-spacing: -0.01em; }
        /* Rounded geometric type like the visitor-kiosk reference: SF Pro Rounded on iPad/macOS, system sans elsewhere. */
        body { font-family: ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", system-ui, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-base-200 text-base-content antialiased">
    {{ $slot }}
</body>
</html>
