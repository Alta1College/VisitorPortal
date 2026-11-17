<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/*
|--------------------------------------------------------------------------
| Public visitor surface
|--------------------------------------------------------------------------
|
| Anonymous kiosk, QR/mobile and sign-out endpoints. Separate from routes/web.php: loaded by
| PublicVisitServiceProvider under the "public-visitor" middleware group (session, CSRF,
| security headers; no authentication). Routes here may only call App\PublicVisit\Commands and
| the HostDirectory, never authenticated controllers, Livewire components or Filament actions,
| and must never accept database ids. See docs/fork/phase-4-public-boundary.md.
|
| Phase 5 adds the kiosk routes, Phase 6 QR/mobile, Phase 7 email sign-out.
|
*/
