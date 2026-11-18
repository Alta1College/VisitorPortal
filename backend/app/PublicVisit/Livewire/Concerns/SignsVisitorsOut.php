<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Livewire\Concerns;

use App\PublicVisit\ActiveAttendances;
use App\PublicVisit\Commands\RecordDeparture;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "End visit" on a kiosk (brief §17): the visitor names themselves and confirms. Unattended
 * kiosks need the exact first and last name; staffed kiosks suggest names as you type.
 * Matches are encrypted, site-bound, short-lived option tokens; only "Pat P." is shown.
 */
trait SignsVisitorsOut
{
    public string $signOutFirst = '';

    public string $signOutLast = '';

    public string $signOutQuery = '';

    /** @var list<array{token: string, name: string, since: string}> */
    public array $signOutResults = [];

    public ?string $signOutToken = null;

    public ?string $signOutName = null;

    public ?string $goodbyeName = null;

    /** Whether this screen offers name-based sign-out (kiosks only: phones are internet-reachable). */
    protected function nameSignOutEnabled(): bool
    {
        return false;
    }

    /** Staffed kiosks suggest names as the visitor types. */
    protected function staffed(): bool
    {
        return false;
    }

    public function startSignOut(): void
    {
        abort_unless($this->nameSignOutEnabled(), 404);

        $this->clearSignOut();
        $this->resetErrorBag();
        $this->step = 'signout';
    }

    public function updatedSignOutQuery(): void
    {
        $this->signOutResults = [];

        if (! $this->nameSignOutEnabled() || ! $this->staffed() || mb_strlen(trim($this->signOutQuery)) < 2) {
            return;
        }

        if (! RateLimiter::attempt('public-signout-search|'.$this->throttleScope(), 30, fn () => true, 60)) {
            $this->addError('signout', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $this->signOutResults = app(ActiveAttendances::class)->suggest($this->site(), $this->signOutQuery);
    }

    public function findSignOut(): void
    {
        abort_unless($this->nameSignOutEnabled(), 404);
        $this->resetErrorBag();

        if (! RateLimiter::attempt('public-signout-search|'.$this->throttleScope(), 10, fn () => true, 60)) {
            $this->addError('signout', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $this->signOutResults = app(ActiveAttendances::class)->exact($this->site(), $this->signOutFirst, $this->signOutLast);

        if ($this->signOutResults === []) {
            $this->addError('signout', __('Wir konnten Ihren Besuch nicht finden. Bitte prüfen Sie die Schreibweise oder wenden Sie sich an den Empfang.'));
        } elseif (count($this->signOutResults) === 1) {
            $this->chooseSignOut(0);
        }
    }

    public function chooseSignOut(int $index): void
    {
        $option = $this->signOutResults[$index] ?? null;

        if ($option === null) {
            $this->addError('signout', __('Bitte wählen Sie Ihren Namen erneut aus.'));

            return;
        }

        $this->signOutToken = $option['token'];
        $this->signOutName = $option['name'];
        $this->step = 'signout_confirm';
    }

    public function confirmSignOut(): void
    {
        abort_unless($this->nameSignOutEnabled(), 404);
        $attendance = $this->signOutToken !== null ? app(ActiveAttendances::class)->resolve($this->site(), $this->signOutToken) : null;

        try {
            if ($attendance === null) {
                throw ValidationException::withMessages(['signout' => 'not active']);
            }

            $this->goodbyeName = app(RecordDeparture::class)->handleAttendance($attendance[0], $attendance[1], $this->channel());
        } catch (ValidationException) {
            $this->clearSignOut();
            $this->step = 'signout';
            $this->addError('signout', __('Wir konnten Ihren Besuch nicht finden. Bitte prüfen Sie die Schreibweise oder wenden Sie sich an den Empfang.'));

            return;
        }

        $this->clearSignOut();
        $this->step = 'signed_out';
    }

    protected function clearSignOut(): void
    {
        $this->signOutFirst = '';
        $this->signOutLast = '';
        $this->signOutQuery = '';
        $this->signOutResults = [];
        $this->signOutToken = null;
        $this->signOutName = null;
    }
}
