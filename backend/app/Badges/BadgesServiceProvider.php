<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use App\Badges\Http\ReprintBadgeController;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Badge printing to network label printers (docs/fork/phase-8-badge-printing.md).
 */
class BadgesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PrinterTransport::class, SocketPrinterTransport::class);
    }

    public function boot(): void
    {
        Gate::policy(BadgePrinter::class, BadgePrinterPolicy::class);

        // Reception: send a badge to the site's label printer (next to upstream's PDF badge).
        Route::middleware(['web', 'auth', 'verified'])
            ->post('/reception/visits/{visit}/participants/{visitor}/label', ReprintBadgeController::class)
            ->whereNumber('visit')
            ->whereNumber('visitor')
            ->name('reception.participants.label');
    }
}
