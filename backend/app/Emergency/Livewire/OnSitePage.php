<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Livewire;

use App\Emergency\OnSiteRegister;
use App\Emergency\RollCall;
use App\Emergency\RollCalls;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "On site now": everyone signed in at a site, and the button that starts a roll call.
 */
class OnSitePage extends Component
{
    public ?int $siteId = null;

    public function mount(): void
    {
        $sites = $this->register()->sitesFor($this->user());
        abort_if($sites->isEmpty(), 403);

        $this->siteId = $this->user()->site_id !== null && $sites->contains('id', $this->user()->site_id)
            ? (int) $this->user()->site_id
            : (int) $sites->first()->getKey();
    }

    public function startRollCall(string $kind): void
    {
        $site = $this->site();
        $rollCall = app(RollCalls::class)->start($site, $this->user(), $kind);

        $this->redirectRoute('reception.roll-calls.show', $rollCall, navigate: false);
    }

    public function render(): View
    {
        $site = $this->site();

        return view('emergency.on-site-page', [
            'sites' => $this->register()->sitesFor($this->user()),
            'site' => $site,
            'people' => $this->register()->people($site),
            'openRollCall' => app(RollCalls::class)->openFor($site->getKey()),
            'history' => RollCall::query()->where('site_id', $site->getKey())->latest('started_at')->limit(10)->get(),
            'kinds' => RollCall::kinds(),
        ]);
    }

    private function site(): Site
    {
        abort_unless($this->siteId !== null && $this->register()->canAccess($this->user(), $this->siteId), 403);

        return Site::query()->findOrFail($this->siteId);
    }

    private function register(): OnSiteRegister
    {
        return app(OnSiteRegister::class);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
