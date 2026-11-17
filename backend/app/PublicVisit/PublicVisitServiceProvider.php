<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\HostDirectory\HostDirectory;
use App\HostDirectory\LocalHostDirectory;
use App\Http\Middleware\EnsureRequiredMfaIsConfigured;
use App\PublicVisit\Console\PrunePublicVisitData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The public visitor boundary: its own route file and middleware group, rate limiters, host
 * directory binding and housekeeping.
 */
class PublicVisitServiceProvider extends ServiceProvider
{
    public const MIDDLEWARE_GROUP = 'public-visitor';

    public function register(): void
    {
        $this->app->bind(HostDirectory::class, LocalHostDirectory::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        // Same session, cookie, CSRF and security-header stack as the web group, but its own
        // group so public routes can never pick up auth or Filament middleware by accident.
        // Staff MFA enforcement is left out: public pages never act as the signed-in staff user.
        $router = $this->app['router'];
        $router->middlewareGroup(self::MIDDLEWARE_GROUP, array_values(array_filter(
            $router->getMiddlewareGroups()['web'] ?? [],
            fn (string $middleware): bool => $middleware !== EnsureRequiredMfaIsConfigured::class,
        )));

        Route::middleware(self::MIDDLEWARE_GROUP)->group(base_path('routes/public.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([PrunePublicVisitData::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('visitorportal:public:prune')->dailyAt('04:10')->withoutOverlapping();
        });
    }

    /**
     * Keys combine the client address with the device/site context the channel adds as the
     * "public_context" request attribute, so one busy kiosk does not throttle a whole network.
     */
    private function registerRateLimiters(): void
    {
        $key = fn (Request $request, string $name): string => $name.'|'.$request->ip().'|'.(string) $request->attributes->get('public_context', '-');

        RateLimiter::for('public-host-search', fn (Request $request) => Limit::perMinute(30)->by($key($request, 'hosts')));
        RateLimiter::for('public-arrival', fn (Request $request) => Limit::perMinute(10)->by($key($request, 'arrival')));
        RateLimiter::for('public-departure', fn (Request $request) => [
            Limit::perMinute(10)->by($key($request, 'departure')),
            // Token guessing is hopeless (~238 bits), but cap failures per address anyway.
            Limit::perHour(60)->by('departure-ip|'.$request->ip()),
        ]);
    }
}
