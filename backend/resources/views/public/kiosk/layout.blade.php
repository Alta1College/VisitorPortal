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
        /* Kiosk ergonomics: no text selection, no callouts, large tap targets. */
        html, body { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; overscroll-behavior: none; }
        input, textarea { -webkit-user-select: text; user-select: text; }
        .kiosk-tap { min-height: 3.5rem; font-size: 1.25rem; }
    </style>
</head>
<body class="min-h-screen bg-base-200 text-base-content antialiased">
    {{ $slot }}
</body>
</html>
