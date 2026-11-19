<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Emergency\Console\PurgeRollCalls;
use App\Emergency\Console\RunAutoSignOut;
use App\Emergency\Http\RollCallController;
use App\Emergency\Livewire\OnSitePage;
use App\Emergency\Livewire\RollCallPage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Active visitor register, emergency roll calls and automatic sign-out
 * (docs/fork/phase-9-emergency.md).
 */
class EmergencyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('emergency.on-site-page', OnSitePage::class);
        Livewire::component('emergency.roll-call-page', RollCallPage::class);

        Route::middleware(['web', 'auth', 'verified'])->prefix('reception')->name('reception.')->group(function (): void {
            Route::get('/on-site', [RollCallController::class, 'onSite'])->name('on-site');
            Route::get('/roll-calls/{rollCall}', [RollCallController::class, 'show'])->whereNumber('rollCall')->name('roll-calls.show');
            Route::get('/roll-calls/{rollCall}/print', [RollCallController::class, 'print'])->whereNumber('rollCall')->name('roll-calls.print');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RunAutoSignOut::class, PurgeRollCalls::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('visitorportal:auto-sign-out')->everyFiveMinutes()->withoutOverlapping()->name('visitorportal-auto-sign-out');

            if (config('privacy.purge_enabled')) {
                $schedule->command('visitorportal:roll-calls:purge')->dailyAt('03:20')->withoutOverlapping()->name('visitorportal-roll-calls-purge');
            }
        });
    }
}
