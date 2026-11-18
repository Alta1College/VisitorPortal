<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Http;

use App\Emergency\OnSiteRegister;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class RollCallController extends Controller
{
    public function onSite(Request $request, OnSiteRegister $register): View
    {
        abort_if($register->sitesFor($this->user($request))->isEmpty(), 403);

        return view('emergency.on-site');
    }

    public function show(Request $request, RollCall $rollCall, OnSiteRegister $register): View
    {
        abort_unless($register->canAccess($this->user($request), $rollCall->site_id), 403);

        return view('emergency.roll-call', ['rollCall' => $rollCall]);
    }

    /**
     * Paper copy (power or network failure, handing to emergency services).
     */
    public function print(Request $request, RollCall $rollCall, OnSiteRegister $register): View
    {
        abort_unless($register->canAccess($this->user($request), $rollCall->site_id), 403);
        $rollCall->load('site');

        return view('emergency.roll-call-print', [
            'rollCall' => $rollCall,
            'entries' => $rollCall->entries()
                ->orderByRaw("CASE status WHEN 'unaccounted' THEN 0 WHEN 'accounted' THEN 1 ELSE 2 END")
                ->orderBy('first_name')->get(),
            'timezone' => $rollCall->site->timezone ?: (string) config('app.timezone'),
            'statusLabels' => RollCallEntry::statusLabels(),
            'kinds' => RollCall::kinds(),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
