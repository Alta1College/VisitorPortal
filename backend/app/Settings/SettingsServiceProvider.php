<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

use App\Settings\Http\BrandStylesheetController;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Organisation settings overlay. Registered before the admin panel provider so that panel
 * branding reads the overlaid values.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);

        // Booting callbacks run before any provider's boot(), so every consumer of
        // config('branding.*'), config('privacy.*') etc. sees the stored values.
        $this->app->booting(function (): void {
            $this->app->make(SettingsRepository::class)->applyOverlay();
        });
    }

    public function boot(): void
    {
        Route::get('/brand.css', BrandStylesheetController::class)->name('brand.stylesheet');

        FilamentColor::register(function (): array {
            $primary = BrandStylesheet::tokens()['primary'] ?? null;

            return $primary !== null ? ['primary' => Color::hex($primary)] : [];
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): HtmlString => new HtmlString('<link rel="stylesheet" href="'.e(BrandStylesheet::url()).'">'),
        );

        // Queue workers are long-running: pick up settings saved after the worker started.
        Event::listen(JobProcessing::class, function (): void {
            try {
                $this->app->make(SettingsRepository::class)->refreshIfStale();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
