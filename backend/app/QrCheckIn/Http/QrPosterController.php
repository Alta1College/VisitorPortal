<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\QrCheckIn\Http;

use App\QrCheckIn\SiteQrLink;
use App\QrCheckIn\SiteQrLinks;
use App\Settings\SettingsRepository;
use Illuminate\Contracts\View\View;

/**
 * Printable A4 poster with a site's mobile check-in QR code, for sites without a tablet or as
 * a second way in while the kiosk is busy. Staff only (QR check-in permission).
 */
class QrPosterController
{
    public function __invoke(SiteQrLink $siteQrLink, SiteQrLinks $links): View
    {
        $site = $siteQrLink->site;
        $settings = app(SettingsRepository::class)->forSite($site);
        $url = $links->url($siteQrLink);

        return view('public.qr.poster', [
            'siteName' => $site->name,
            'logo' => $settings->get('branding.logo_light'),
            'heading' => $settings->get('organisation.visitor_experience.welcome_heading') ?: __('Gäste, bitte hier beginnen.'),
            'qr' => $links->svg($url, 600),
            'url' => $url,
            'enabled' => $siteQrLink->is_enabled,
        ]);
    }
}
