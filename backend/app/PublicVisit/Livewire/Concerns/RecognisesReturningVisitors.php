<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Livewire\Concerns;

use App\Models\Visitor;
use App\PublicVisit\ReturningVisitors;
use App\Settings\SettingsRepository;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * "Been here before?" on the sign-in screens. See ReturningVisitors for the privacy rules: exact
 * email or mobile plus last name, a masked summary to confirm, saved values merged on the server.
 */
trait RecognisesReturningVisitors
{
    /** Confirmed by the visitor: RegisterArrival reuses this visitor record. */
    #[Locked]
    public ?string $returningToken = null;

    /** Found (or remembered on this phone) but not yet confirmed. */
    #[Locked]
    public ?string $pendingReturningToken = null;

    /** @var array<string, mixed> masked summary only */
    #[Locked]
    public array $returningSummary = [];

    /** @var list<string> fields that have a saved value (never the values) */
    #[Locked]
    public array $savedFields = [];

    public string $lookupIdentifier = '';

    public string $lookupLastName = '';

    public function startReturning(): void
    {
        abort_unless($this->returningEnabled(), 404);

        $this->resetErrorBag();
        $this->step = 'returning';
    }

    public function lookupReturning(): void
    {
        abort_unless($this->returningEnabled(), 404);
        $this->resetErrorBag();

        if (! RateLimiter::attempt('public-returning|'.$this->throttleScope(), 10, fn () => true, 60)) {
            $this->addError('lookup', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $visitor = app(ReturningVisitors::class)->find($this->lookupIdentifier, $this->lookupLastName);
        $this->lookupIdentifier = '';
        $this->lookupLastName = '';

        if ($visitor === null) {
            // One message whatever did not match: nothing is revealed about any record.
            $this->addError('lookup', __('Wir haben keine gespeicherten Angaben gefunden. Bitte geben Sie Ihre Angaben ein.'));

            return;
        }

        $this->offerReturning($visitor);
        $this->step = 'details';
    }

    public function confirmReturning(): void
    {
        $returning = app(ReturningVisitors::class);
        $visitor = $returning->resolve($this->pendingReturningToken);

        if ($visitor === null) {
            $this->clearReturning();

            return;
        }

        $this->returningToken = $returning->token($visitor);
        $this->pendingReturningToken = null;
        $this->savedFields = $returning->savedFields($visitor);
        $this->details = ['first_name' => $visitor->first_name, 'name' => $visitor->name, 'email' => null, 'phone' => null, 'company' => null];
    }

    public function declineReturning(): void
    {
        $this->clearReturning();
        $this->details = ['first_name' => null, 'name' => null, 'email' => null, 'phone' => null, 'company' => null];
    }

    protected function offerReturning(Visitor $visitor): void
    {
        $returning = app(ReturningVisitors::class);

        $this->pendingReturningToken = $returning->token($visitor);
        $this->returningSummary = $returning->summary($visitor);
        $this->returningToken = null;
        $this->savedFields = [];
    }

    protected function clearReturning(): void
    {
        $this->returningToken = null;
        $this->pendingReturningToken = null;
        $this->returningSummary = [];
        $this->savedFields = [];
    }

    protected function returningVisitor(): ?Visitor
    {
        return app(ReturningVisitors::class)->resolve($this->returningToken);
    }

    /**
     * What the details step validates: typed values, with saved ones for empty fields.
     *
     * @return array<string, mixed>
     */
    protected function detailsForValidation(): array
    {
        $visitor = $this->returningVisitor();

        return $visitor !== null ? app(ReturningVisitors::class)->merge($visitor, $this->details) : $this->details;
    }

    protected function returningEnabled(): bool
    {
        return (string) app(SettingsRepository::class)->forSite($this->site())
            ->get('organisation.visitor_experience.returning_visitors') !== '0';
    }
}
