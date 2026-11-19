<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\QrCheckIn\Livewire;

use App\Models\Site;
use App\PublicVisit\AttendanceTokens;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalResult;
use App\PublicVisit\Commands\RecordDeparture;
use App\PublicVisit\Livewire\VisitorSignIn;
use App\QrCheckIn\SiteQrLink;
use App\QrCheckIn\SiteQrLinks;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Sign-in from the visitor's own phone after scanning a site's QR code (/visit/{token}).
 * Same steps and the same RegisterArrival command as the kiosk; only the context differs.
 * The page is reachable from the internet, so the link is re-checked on every request
 * (replacing or disabling it stops open pages at once) and host search needs 3 characters.
 * Returning visitors are found by email or mobile number in the database, as on the kiosk;
 * the phone keeps nothing but the sign-out cookie of a visit that is still open.
 */
class MobileSignIn extends VisitorSignIn
{
    /** The opaque link token from the URL (already public: it is printed on the poster). */
    #[Locked]
    public string $token = '';

    /** Cookie on the visitor's phone holding their departure token, so scanning again offers "Sign out". */
    public const DEPARTURE_COOKIE = 'vp_departure';

    private ?SiteQrLink $link = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->startScreen();
    }

    /**
     * Sign out the visit this phone signed in (departure token from the cookie).
     */
    public function signOutHere(): void
    {
        $token = (string) request()->cookie(self::DEPARTURE_COOKIE, '');

        try {
            $this->goodbyeName = app(RecordDeparture::class)->handle($token, Channel::Qr);
        } catch (ValidationException $e) {
            $this->addError('submit', $e->validator->errors()->first());
            Cookie::queue(Cookie::forget(self::DEPARTURE_COOKIE));

            return;
        }

        Cookie::queue(Cookie::forget(self::DEPARTURE_COOKIE));
        $this->step = 'signed_out';
    }

    protected function afterArrival(ArrivalResult $result): void
    {
        $minutes = AttendanceTokens::DEPARTURE_TTL_HOURS * 60;
        Cookie::queue(Cookie::make(self::DEPARTURE_COOKIE, $result->departureToken, $minutes, null, null, request()->isSecure(), true, false, 'lax'));
    }

    protected function activeVisitName(): ?string
    {
        $token = request()->cookie(self::DEPARTURE_COOKIE);

        return is_string($token) && $token !== '' ? app(RecordDeparture::class)->tokenIsActiveAt($token, $this->site()->getKey()) : null;
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
