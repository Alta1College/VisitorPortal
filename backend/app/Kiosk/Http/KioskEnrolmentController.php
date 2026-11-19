<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk\Http;

use App\Kiosk\KioskDevices;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class KioskEnrolmentController
{
    public function __construct(private readonly KioskDevices $devices) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->devices->fromRequest($request) !== null) {
            return redirect()->route('kiosk');
        }

        return view('public.kiosk.enrol');
    }

    public function store(Request $request): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];

        $result = $this->devices->enrol($code);

        if ($result === null) {
            return back()->withErrors(['code' => __('Dieser Code ist ungültig oder abgelaufen.')]);
        }

        [, $secret] = $result;

        return redirect()->route('kiosk')->withCookie(Cookie::make(
            KioskDevices::COOKIE,
            $secret,
            KioskDevices::COOKIE_MINUTES,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }
}
