<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\QrCheckIn\Livewire;

use App\Models\Site;
use App\PublicVisit\Channel;
use App\PublicVisit\Livewire\VisitorSignIn;
use App\QrCheckIn\SiteQrLink;
use App\QrCheckIn\SiteQrLinks;
use Livewire\Attributes\Locked;

/**
 * Sign-in from the visitor's own phone after scanning a site's QR code (/visit/{token}).
 * Same steps and the same RegisterArrival command as the kiosk; only the context differs.
 * The page is reachable from the internet, so the link is re-checked on every request
 * (replacing or disabling it stops open pages at once) and host search needs 3 characters.
 */
class MobileSignIn extends VisitorSignIn
{
    /** The opaque link token from the URL (already public: it is printed on the poster). */
    #[Locked]
    public string $token = '';

    private ?SiteQrLink $link = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->startScreen();
    }

    protected function site(): Site
    {
        return $this->link()->site;
    }

    protected function channel(): Channel
    {
        return Channel::Qr;
    }

    protected function throttleScope(): string
    {
        return 'qr:'.$this->link()->getKey().'|'.request()->ip();
    }

    protected function mode(): string
    {
        return 'mobile';
    }

    protected function minHostQueryLength(): int
    {
        return 3;
    }

    private function link(): SiteQrLink
    {
        $this->link ??= app(SiteQrLinks::class)->find($this->token);

        abort_if($this->link === null, 404);

        return $this->link;
    }
}
