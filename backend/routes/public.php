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
| security headers; no authentication). Routes here may only use App\PublicVisit\Commands, the
| HostDirectory and fork public components (App\Kiosk), never authenticated controllers,
| upstream Livewire components or Filament actions, and must never accept database ids.
| See docs/fork/phase-4-public-boundary.md.
|
| Phase 6 adds QR/mobile, Phase 7 email sign-out.
|
*/

use App\Kiosk\Http\KioskEnrolmentController;
use App\Kiosk\Http\RequireKioskDevice;
use App\Kiosk\Livewire\KioskScreen;
use Illuminate\Support\Facades\Route;

Route::get('/kiosk/enrol', [KioskEnrolmentController::class, 'show'])->name('kiosk.enrol');
Route::post('/kiosk/enrol', [KioskEnrolmentController::class, 'store'])
    ->middleware('throttle:public-kiosk-enrol')
    ->name('kiosk.enrol.store');

Route::get('/kiosk', KioskScreen::class)
    ->middleware(RequireKioskDevice::class)
    ->name('kiosk');
