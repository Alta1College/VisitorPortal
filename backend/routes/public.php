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
| /leave/{token} is the emailed sign-out link (Phase 7).
|
*/

use App\Kiosk\Http\KioskEnrolmentController;
use App\Kiosk\Http\RequireKioskDevice;
use App\Kiosk\Http\ReturningPhotoController;
use App\Kiosk\Livewire\KioskScreen;
use App\PublicVisit\Http\DepartureLinkController;
use App\QrCheckIn\Livewire\MobileSignIn;
use Illuminate\Support\Facades\Route;

Route::get('/kiosk/enrol', [KioskEnrolmentController::class, 'show'])->name('kiosk.enrol');
Route::post('/kiosk/enrol', [KioskEnrolmentController::class, 'store'])
    ->middleware('throttle:public-kiosk-enrol')
    ->name('kiosk.enrol.store');

Route::get('/kiosk', KioskScreen::class)
    ->middleware(RequireKioskDevice::class)
    ->name('kiosk');

// A returning visitor's saved photo on the kiosk (short-lived, site-bound token in the query).
Route::get('/kiosk/returning-photo', ReturningPhotoController::class)
    ->middleware([RequireKioskDevice::class, 'throttle:public-visit-page'])
    ->name('kiosk.returning-photo');

// Mobile check-in from a site's QR code. The token is checked inside the component on every
// request (Livewire updates bypass route middleware).
Route::get('/visit/{token}', MobileSignIn::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:public-visit-page')
    ->name('visit');

// Emailed sign-out link. GET only shows a button; the POST signs out (link scanners fetch GET).
Route::get('/leave/{token}', [DepartureLinkController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:public-departure')
    ->name('leave');
Route::post('/leave/{token}', [DepartureLinkController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:public-departure')
    ->name('leave.store');
