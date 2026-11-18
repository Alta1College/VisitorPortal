<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Http;

use App\Models\Site;
use App\Models\Visit;
use App\PublicVisit\AttendanceTokens;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\RecordDeparture;
use App\Settings\SettingsRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The emailed sign-out link (/leave/{token}). Opening it only shows a button: signing out
 * needs a POST, so mail scanners and link previews that fetch the URL cannot sign anyone out.
 */
class DepartureLinkController
{
    public function show(string $token, RecordDeparture $departures): View
    {
        $row = strlen($token) === 40 ? app(AttendanceTokens::class)->find($token) : null;
        $visit = $row ? Visit::query()->with('site')->find($row->visit_id) : null;
        $firstName = $visit !== null ? $departures->tokenIsActiveAt($token, $visit->site_id) : null;

        return $this->page($visit?->site, $firstName !== null ? 'confirm' : 'invalid', $firstName, $token);
    }

    public function store(Request $request, string $token, RecordDeparture $departures): View
    {
        $row = strlen($token) === 40 ? app(AttendanceTokens::class)->find($token) : null;
        $site = $row ? Visit::query()->with('site')->find($row->visit_id)?->site : null;

        try {
            $firstName = $departures->handle($token, Channel::EmailLink);
        } catch (ValidationException) {
            return $this->page($site, 'invalid', null, null);
        }

        return $this->page($site, 'done', $firstName, null);
    }

    private function page(?Site $site, string $state, ?string $firstName, ?string $token): View
    {
        $settings = $site !== null ? app(SettingsRepository::class)->forSite($site) : null;

        return view('public.leave', [
            'state' => $state,
            'firstName' => $firstName,
            'token' => $token,
            'siteName' => $site?->name,
            'logo' => $settings?->get('branding.logo_light') ?? config('branding.logo_light'),
            'confirmation' => $settings?->get('organisation.visitor_experience.sign_out_confirmation'),
        ]);
    }
}
