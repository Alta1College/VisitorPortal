<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Livewire\Concerns;

use App\Models\Visitor;
use App\PublicVisit\ReturningVisitors;
use App\PublicVisit\VisitorDetails;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\FormField;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * Returning visitors on the sign-in screens: the visitor starts with their email address or
 * mobile number; an exact match offers the saved record ("That's me"), which pre-fills the
 * details, the remembered answers and the photo. See ReturningVisitors for the privacy rules
 * (full values on the kiosk, masked and merged on the server on phones).
 */
trait RecognisesReturningVisitors
{
    /** Confirmed by the visitor: RegisterArrival reuses this visitor record. */
    #[Locked]
    public ?string $returningToken = null;

    /** Found by email or mobile number but not yet confirmed. */
    #[Locked]
    public ?string $pendingReturningToken = null;

    /** @var array<string, mixed> summary for "is this you?" (masked on phones) */
    #[Locked]
    public array $returningSummary = [];

    /** @var list<string> detail fields with a saved value that is not on screen (phones) */
    #[Locked]
    public array $savedFields = [];

    /** @var list<string> remembered answers that are not on screen (files, sensitive, phones) */
    #[Locked]
    public array $savedAnswerKeys = [];

    public string $lookupIdentifier = '';

    /**
     * "Email or mobile number": an exact match offers the saved record, anything else carries
     * the value into the details form.
     */
    public function lookupReturning(): void
    {
        abort_unless($this->returningEnabled() && $this->typeSlug !== null, 404);
        $this->resetErrorBag();

        $identifier = trim($this->lookupIdentifier);

        if ($identifier === '') {
            $this->step = 'details';

            return;
        }

        if (! RateLimiter::attempt('public-returning|'.$this->throttleScope(), 10, fn () => true, 60)) {
            $this->addError('lookup', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $visitor = app(ReturningVisitors::class)->find($identifier);
        $this->lookupIdentifier = '';

        if ($visitor !== null) {
            $this->offerReturning($visitor);
        } else {
            $this->clearReturning();
            $field = str_contains($identifier, '@') ? 'email' : 'phone';

            if (in_array($field, VisitorDetails::visible($this->visitorType()), true)) {
                $this->details[$field] = mb_substr($identifier, 0, 191);
            }
        }

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
        $this->answers = [];
        $this->savedAnswerKeys = [];

        if ($this->showsSavedValues()) {
            // Kiosk: the saved values are shown and can be edited.
            $this->details = array_map(fn ($value) => $value === null ? null : (string) $value, $visitor->only(ReturningVisitors::SAVED_FIELDS));
            $this->savedFields = [];
        } else {
            // Phone: nothing saved reaches the screen; empty fields keep the saved value.
            $this->details = ['first_name' => null, 'name' => null, 'email' => null, 'phone' => null, 'company' => null];
            $this->savedFields = $returning->savedFields($visitor);
        }

        $definition = $this->definition();

        foreach ($returning->savedAnswers($visitor, $definition) as $key => $response) {
            $field = $definition->field($key)->field;

            if ($this->showsSavedValues() && $response->file_path === null && ! $field->is_sensitive) {
                $this->answers[$key] = $response->plainValue();
            } else {
                $this->savedAnswerKeys[] = $key;
            }
        }
    }

    public function declineReturning(): void
    {
        $this->clearReturning();
        $this->details = ['first_name' => null, 'name' => null, 'email' => null, 'phone' => null, 'company' => null];
        $this->answers = [];

        // Someone else (e.g. on a borrowed phone): let them look up their own record.
        if ($this->stepApplies('identify')) {
            $this->step = 'identify';
        }
    }

    protected function offerReturning(Visitor $visitor): void
    {
        $returning = app(ReturningVisitors::class);

        $this->pendingReturningToken = $returning->token($visitor);
        $this->returningSummary = $returning->summary($visitor, $this->showsSavedValues());
        $this->returningToken = null;
        $this->savedFields = [];
        $this->savedAnswerKeys = [];
    }

    protected function clearReturning(): void
    {
        $this->returningToken = null;
        $this->pendingReturningToken = null;
        $this->returningSummary = [];
        $this->savedFields = [];
        $this->savedAnswerKeys = [];
    }

    protected function returningVisitor(): ?Visitor
    {
        return app(ReturningVisitors::class)->resolve($this->returningToken);
    }

    /**
     * The saved photo of the visitor being offered or confirmed, as a kiosk-only image URL.
     */
    protected function returningPhotoUrl(): ?string
    {
        if (! $this->showsSavedValues() || ($this->returningToken === null && $this->pendingReturningToken === null)) {
            return null;
        }

        $returning = app(ReturningVisitors::class);
        $visitor = $returning->resolve($this->returningToken ?? $this->pendingReturningToken);
        $path = $visitor !== null ? $returning->latestPhoto($visitor) : null;

        return $path !== null ? route('kiosk.returning-photo', ['token' => $returning->photoToken($path, $this->site()->getKey())]) : null;
    }

    /**
     * Saved photos for remembered photo questions (kiosk only), keyed by field key.
     *
     * @return array<string, string>
     */
    protected function savedAnswerPhotos(): array
    {
        $visitor = $this->showsSavedValues() ? $this->returningVisitor() : null;

        if ($visitor === null || $this->typeSlug === null) {
            return [];
        }

        $returning = app(ReturningVisitors::class);
        $photos = [];

        foreach ($returning->savedAnswers($visitor, $this->definition()) as $key => $response) {
            if ($response->field_type === FormField::TYPE_PHOTO && $response->file_path !== null) {
                $photos[$key] = route('kiosk.returning-photo', ['token' => $returning->photoToken($response->file_path, $this->site()->getKey())]);
            }
        }

        return $photos;
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

    /**
     * Answers with remembered ones for questions left empty.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    protected function answersForValidation(array $answers): array
    {
        $visitor = $this->returningVisitor();

        return $visitor !== null ? app(ReturningVisitors::class)->mergeAnswers($visitor, $this->definition(), $answers) : $answers;
    }

    /** The kiosk shows saved values; phones (internet-reachable) only a masked summary. */
    protected function showsSavedValues(): bool
    {
        return $this->mode() === 'kiosk';
    }

    protected function returningEnabled(): bool
    {
        return (string) app(SettingsRepository::class)->forSite($this->site())
            ->get('organisation.visitor_experience.returning_visitors') !== '0';
    }
}
