<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk\Livewire;

use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Models\Site;
use App\PublicVisit\Channel;
use App\PublicVisit\Livewire\VisitorSignIn;
use App\QrCheckIn\SiteQrLinks;

/**
 * Sign-in on an enrolled kiosk. The kiosk (and so the site) is re-identified from the device
 * cookie on every request; the welcome screen offers the site's mobile check-in QR code so a
 * queue can scan in while the tablet is busy.
 */
class KioskScreen extends VisitorSignIn
{
    public function mount(): void
    {
        $this->startScreen();
    }

    protected function site(): Site
    {
        return $this->kiosk()->site;
    }

    protected function channel(): Channel
    {
        return Channel::Kiosk;
    }

    protected function kioskId(): ?int
    {
        return $this->kiosk()->getKey();
    }

    protected function throttleScope(): string
    {
        return 'kiosk:'.$this->kiosk()->getKey();
    }

    protected function mode(): string
    {
        return 'kiosk';
    }

    protected function idleSeconds(): ?int
    {
        return max(15, (int) $this->kiosk()->idle_timeout_seconds);
    }

    protected function welcomeQr(): ?string
    {
        $links = app(SiteQrLinks::class);
        $link = $links->enabledForSite($this->site());

        return $link !== null ? $links->svg($links->url($link), 200) : null;
    }

    private function kiosk(): Kiosk
    {
        $kiosk = request()->attributes->get('kiosk') ?? app(KioskDevices::class)->fromRequest(request());

        abort_unless($kiosk instanceof Kiosk, 403);
        request()->attributes->set('kiosk', $kiosk);

        return $kiosk;
    }
}
