{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
{{-- Printable A4 mobile check-in poster (fork). Self-contained layout in millimetres so it prints
     the same everywhere; only the organisation colours come from brand.css. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-preference="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Aushang: :site', ['site' => $siteName]) }}</title>
    <x-brand-stylesheet />
    <style>
        @page { size: A4 portrait; margin: 0; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff !important; }
        body {
            --ink: var(--color-base-content, #1f2937);
            --brand: var(--color-primary, #374151);
            color: var(--ink);
            font-family: ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", system-ui, -apple-system, "Segoe UI", sans-serif;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        /* One A4 sheet. Rows: header, heading, subheading, QR (takes the remaining space),
           steps, address, colour band. The QR shrinks if a long heading needs more room. */
        .sheet {
            width: 210mm; height: 297mm; padding: 16mm 18mm 14mm; overflow: hidden; background: #fff;
            display: grid; grid-template-rows: auto auto auto minmax(0, 1fr) auto auto auto; row-gap: 0;
        }
        .head { display: flex; align-items: center; justify-content: space-between; gap: 8mm; }
        .logo { height: 22mm; max-width: 110mm; object-fit: contain; object-position: left center; }
        .org { font-size: 10mm; font-weight: 700; }
        .site { font-size: 5mm; font-weight: 600; text-align: right; }
        h1 { margin: 16mm 0 0; font-size: 15mm; font-weight: 700; line-height: 1.1; letter-spacing: -0.01em; overflow-wrap: anywhere; }
        .sub { margin: 4mm 0 0; font-size: 8mm; font-weight: 600; line-height: 1.2; }
        .qr-row { min-height: 0; display: flex; align-items: center; justify-content: center; padding: 10mm 0; }
        .qr {
            height: 100%; max-height: 118mm; aspect-ratio: 1 / 1;
            padding: 5mm; border: 1.6mm solid var(--brand); border-radius: 8mm; background: #fff;
        }
        .qr svg { display: block; width: 100%; height: 100%; }
        .steps { margin: 0 auto; padding: 0; list-style: none; display: grid; row-gap: 3mm; font-size: 6.2mm; line-height: 1.25; }
        .steps li { display: grid; grid-template-columns: 9mm 1fr; align-items: baseline; }
        .steps b { font-weight: 800; color: var(--ink); }
        .url { margin: 8mm 0 0; font-size: 3.2mm; text-align: center; overflow-wrap: anywhere; opacity: .85; }
        .band { margin-top: 4mm; height: 7mm; border-radius: 3.5mm; background: var(--brand); }

        /* Screen preview: grey desk, the sheet scaled down to fit the window. */
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; max-width: 210mm; margin: 0 auto; padding: 20px 16px 0; font-size: 14px; }
        .toolbar button { border: 0; border-radius: 999px; padding: 10px 24px; font: inherit; font-weight: 700; cursor: pointer;
                          background: var(--brand); color: var(--color-primary-content, #fff); }
        .warn { color: #b91c1c; font-weight: 700; }
        .desk { padding: 20px 16px 40px; }
        .frame { margin: 0 auto; width: calc(210mm * var(--s, 1)); height: calc(297mm * var(--s, 1)); }
        .frame .sheet { transform: scale(var(--s, 1)); transform-origin: top left; box-shadow: 0 4px 24px rgb(0 0 0 / .15); }
        @media screen { html, body { background: #e5e7eb !important; } }
        @media print {
            .toolbar { display: none !important; }
            .desk { padding: 0; }
            .frame { width: auto; height: auto; }
            .frame .sheet { transform: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <span>@unless ($enabled)<span class="warn">{{ __('Ausgeschaltet: der Link funktioniert gerade nicht.') }}</span>@endunless</span>
        <button type="button" onclick="window.print()">{{ __('Drucken') }}</button>
    </div>

    <div class="desk">
        <div class="frame" id="frame">
            <main class="sheet" id="sheet">
                <header class="head">
                    @if ($logo)
                        <img class="logo" src="{{ asset($logo) }}" alt="{{ config('branding.name') }}">
                    @else
                        <span class="org">{{ config('branding.name') }}</span>
                    @endif
                    <span class="site">{{ $siteName }}</span>
                </header>

                <h1>{{ $heading }}</h1>
                <p class="sub">{{ __('Mit dem Handy anmelden') }}</p>

                <div class="qr-row"><div class="qr">{!! $qr !!}</div></div>

                <ol class="steps">
                    <li><b>1.</b><span>{{ __('Öffnen Sie die Kamera Ihres Handys.') }}</span></li>
                    <li><b>2.</b><span>{{ __('Richten Sie sie auf den QR-Code und tippen Sie auf den Link.') }}</span></li>
                    <li><b>3.</b><span>{{ __('Folgen Sie den Schritten, um sich anzumelden.') }}</span></li>
                </ol>

                <p class="url">{{ $url }}</p>
                <div class="band"></div>
            </main>
        </div>
    </div>

    <script>
        // Fit the A4 sheet to the window on screen (printing always uses the real size).
        (function () {
            const frame = document.getElementById('frame');
            const sheet = document.getElementById('sheet');
            const fit = () => {
                const s = Math.min(1, (document.documentElement.clientWidth - 32) / sheet.offsetWidth);
                frame.style.setProperty('--s', String(s));
            };
            addEventListener('resize', fit);
            fit();
        })();
    </script>
</body>
</html>
