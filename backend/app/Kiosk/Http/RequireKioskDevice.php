<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk\Http;

use App\Kiosk\KioskDevices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an enrolled, active kiosk device may use kiosk pages. Registered as Livewire persistent
 * middleware too, so every component update is re-checked, not just the first page load.
 */
class RequireKioskDevice
{
    public function __construct(private readonly KioskDevices $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $kiosk = $this->devices->fromRequest($request);

        if ($kiosk === null) {
            if ($request->hasHeader('X-Livewire')) {
                abort(403);
            }

            return redirect()->route('kiosk.enrol');
        }

        $request->attributes->set('kiosk', $kiosk);
        $request->attributes->set('public_context', 'kiosk:'.$kiosk->getKey());

        return $next($request);
    }
}
