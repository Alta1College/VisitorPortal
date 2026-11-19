<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Livewire;

use App\HostDirectory\HostDirectory;
use App\Models\Site;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalRequest;
use App\PublicVisit\Commands\ArrivalResult;
use App\PublicVisit\Commands\RegisterArrival;
use App\PublicVisit\Livewire\Concerns\RecognisesReturningVisitors;
use App\PublicVisit\Livewire\Concerns\SignsVisitorsOut;
use App\PublicVisit\VisitorDetails;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\ConditionEvaluator;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\ResponseValidator;
use App\VisitorWorkflow\WorkflowDefinition;
use App\VisitorWorkflow\WorkflowResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Visitor sign-in screen, shared by the kiosk and QR/mobile channels (one implementation of
 * visitor registration, brief §16). Subclasses only say where the visitor is (site, channel,
 * device) and how the screen behaves. Everything the browser holds is non-sensitive and
 * contains no database ids:
 * the visitor type by slug, the host by its encrypted option token, policies by content hash.
 * the site is re-identified from the channel (kiosk device cookie, QR link) on every request.
 * The final submit goes through RegisterArrival, the same command every channel uses.
 */
abstract class VisitorSignIn extends Component
{
    use RecognisesReturningVisitors;
    use SignsVisitorsOut;
    use WithFileUploads;

    public const STEPS = ['welcome', 'identify', 'details', 'host', 'questions', 'policies', 'confirm', 'done'];

    public string $step = 'welcome';

    public ?string $typeSlug = null;

    /** @var array<string, string|null> */
    public array $details = ['first_name' => null, 'name' => null, 'email' => null, 'phone' => null, 'company' => null];

    public string $hostQuery = '';

    /** @var list<array{token: string, name: string}> */
    public array $hostResults = [];

    public ?string $hostToken = null;

    public ?string $hostName = null;

    /** @var array<string, mixed> field key => answer */
    public array $answers = [];

    /** @var array<string, bool> policy version content hash => accepted */
    public array $accepted = [];

    #[Locked]
    public string $submissionKey = '';

    public ?string $doneFirstName = null;

    public ?string $doneHostName = null;

    /** A badge is printing for the visitor who just signed in. */
    public bool $doneBadge = false;

    /** The site the visitor is at; aborts if the channel is no longer valid. */
    abstract protected function site(): Site;

    abstract protected function channel(): Channel;

    /** Kiosk that records the sign-in, if any. */
    protected function kioskId(): ?int
    {
        return null;
    }

    /** Rate-limit scope: the device, or the QR link and client address. */
    abstract protected function throttleScope(): string;

    /** "kiosk" (shared screen: idle reset, fixed layout) or "mobile" (visitor's own phone). */
    abstract protected function mode(): string;

    /** Inactivity reset in seconds; null for none. */
    protected function idleSeconds(): ?int
    {
        return null;
    }

    /** Minimum characters before the host search runs (more where the page is internet-reachable). */
    protected function minHostQueryLength(): int
    {
        return 2;
    }

    /** First name of the visitor signed in on this device, if the screen can tell (phones). */
    protected function activeVisitName(): ?string
    {
        return null;
    }

    /** SVG of the mobile check-in QR code for the welcome screen, if offered. */
    protected function welcomeQr(): ?string
    {
        return null;
    }

    /** Called from each screen's mount() (screens take different route parameters). */
    protected function startScreen(): void
    {
        $this->site();
        $this->submissionKey = (string) Str::uuid();
    }

    /**
     * The welcome screen lists the visitor types; tapping one starts the sign-in.
     */
    public function chooseType(string $slug): void
    {
        $this->resetErrorBag();
        $type = collect($this->types())->firstWhere('slug', $slug);

        if ($type === null) {
            $this->addError('type', __('Dieser Besuchertyp ist hier nicht verfügbar.'));

            return;
        }

        $this->typeSlug = $type->slug;
        $this->answers = [];
        $this->accepted = [];
        $this->clearReturning();
        // Start with "email or mobile number" so a returning visitor's record can be loaded.
        $this->step = $this->stepApplies('identify') ? 'identify' : 'details';
    }

    public function submitDetails(): void
    {
        $this->resetErrorBag();

        try {
            VisitorDetails::validate($this->detailsForValidation(), $this->visitorType());
        } catch (ValidationException $e) {
            $this->addErrorsUnder('details', $e);

            return;
        }

        $this->step = $this->visitorType()->host_requirement === VisitorType::HOST_NONE ? $this->stepAfterHost() : 'host';
    }

    public function updatedHostQuery(): void
    {
        $this->hostResults = [];

        if (mb_strlen(trim($this->hostQuery)) < $this->minHostQueryLength()) {
            return;
        }

        $allowed = RateLimiter::attempt('public-host-search|'.$this->throttleScope(), 30, fn () => true, 60);

        if (! $allowed) {
            $this->addError('host', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $this->hostResults = array_map(
            fn ($option) => ['token' => $option->token, 'name' => $option->displayName],
            app(HostDirectory::class)->search($this->site(), $this->hostQuery),
        );
    }

    public function chooseHost(int $index): void
    {
        $this->resetErrorBag();
        $option = $this->hostResults[$index] ?? null;

        if ($option === null || app(HostDirectory::class)->resolve($this->site(), $option['token']) === null) {
            $this->addError('host', __('Bitte wählen Sie Ihre Ansprechperson erneut aus.'));

            return;
        }

        $this->hostToken = $option['token'];
        $this->hostName = $option['name'];
        $this->step = $this->stepAfterHost();
    }

    public function skipHost(): void
    {
        if ($this->visitorType()->host_requirement === VisitorType::HOST_REQUIRED) {
            $this->addError('host', __('Bitte wählen Sie aus, wen Sie besuchen.'));

            return;
        }

        $this->hostToken = null;
        $this->hostName = null;
        $this->step = $this->stepAfterHost();
    }

    public function submitQuestions(): void
    {
        $this->resetErrorBag();

        try {
            app(ResponseValidator::class)->validate($this->definition(), $this->answersForValidation($this->preparedAnswers()), [], checkPolicies: false);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->step = $this->definition()->policies !== [] ? 'policies' : 'confirm';
    }

    public function submitPolicies(): void
    {
        $this->resetErrorBag();

        foreach ($this->definition()->policies as $version) {
            if (! ($this->accepted[$version->content_hash] ?? false)) {
                $this->addError('policies.'.$version->content_hash, __(':policy muss akzeptiert werden.', ['policy' => $version->title]));
            }
        }

        if ($this->getErrorBag()->isEmpty()) {
            $this->step = 'confirm';
        }
    }

    public function back(): void
    {
        $special = ['signout' => 'welcome', 'signout_confirm' => 'signout'];

        if (isset($special[$this->step])) {
            $this->resetErrorBag();
            $this->step = $special[$this->step];

            return;
        }

        $order = array_values(array_filter(self::STEPS, fn (string $s): bool => $this->stepApplies($s)));
        $index = array_search($this->step, $order, true);

        $this->resetErrorBag();
        $this->step = $index > 0 ? $order[$index - 1] : 'welcome';
    }

    public function finish(): void
    {
        if (! RateLimiter::attempt('public-arrival|'.$this->throttleScope(), 10, fn () => true, 60)) {
            $this->addError('submit', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $definition = $this->definition();
        $acceptedIds = array_values(array_map(
            fn ($version) => $version->getKey(),
            array_filter($definition->policies, fn ($version) => $this->accepted[$version->content_hash] ?? false),
        ));

        try {
            $result = app(RegisterArrival::class)->handle(new ArrivalRequest(
                site: $this->site(),
                channel: $this->channel(),
                submissionKey: $this->submissionKey,
                visitorType: $this->visitorType(),
                details: $this->details,
                hostToken: $this->hostToken,
                answers: $this->preparedAnswers(),
                acceptedPolicyVersionIds: $acceptedIds,
                kioskId: $this->kioskId(),
                returningToken: $this->returningToken,
            ));
        } catch (ValidationException $e) {
            $this->returnToStepFor($e);

            return;
        }

        $this->doneFirstName = $result->visitorFirstName;
        $this->doneHostName = $result->hostDisplayName;
        $this->doneBadge = $result->badgeQueued;
        $this->afterArrival($result);
        $this->clearReturning();
        $this->step = 'done';
    }

    /** Screens may keep something on the device after sign-in (the phone keeps a sign-out cookie). */
    protected function afterArrival(ArrivalResult $result): void {}

    /**
     * Clear everything the visitor entered (inactivity, cancel, or after the success screen).
     */
    public function restart(): void
    {
        $this->reset(['step', 'typeSlug', 'details', 'hostQuery', 'hostResults', 'hostToken', 'hostName', 'answers', 'accepted', 'doneFirstName', 'doneHostName', 'doneBadge', 'goodbyeName', 'lookupIdentifier']);
        $this->clearReturning();
        $this->clearSignOut();
        $this->resetErrorBag();
        $this->submissionKey = (string) Str::uuid();
    }

    public function render(): View
    {
        $site = $this->site();
        $settings = app(SettingsRepository::class)->forSite($site);
        $definition = $this->typeSlug !== null ? $this->definition() : null;

        return view('public.signin.screen', [
            'mode' => $this->mode(),
            'idleSeconds' => $this->idleSeconds(),
            'minHostQuery' => $this->minHostQueryLength(),
            'welcomeQr' => $this->step === 'welcome' ? $this->welcomeQr() : null,
            'siteName' => $site->name,
            'logo' => $settings->get('branding.logo_light'),
            'greeting' => (string) $settings->get('organisation.visitor_experience.show_greeting') === '0' ? null : $this->greeting($site),
            'headings' => [
                'details' => $settings->get('organisation.visitor_experience.heading_details') ?: __('Ihre Angaben'),
                'host' => $settings->get('organisation.visitor_experience.heading_host') ?: __('Wen besuchen Sie?'),
                'questions' => $settings->get('organisation.visitor_experience.heading_questions') ?: __('Ein paar Fragen'),
                'policies' => $settings->get('organisation.visitor_experience.heading_policies') ?: __('Bitte lesen und bestätigen'),
                'confirm' => $settings->get('organisation.visitor_experience.heading_confirm') ?: __('Alles richtig?'),
                'done' => $settings->get('organisation.visitor_experience.heading_done') ?: __('Danke, :name!'),
            ],
            'welcomeHeading' => $settings->get('organisation.visitor_experience.welcome_heading') ?: __('Gäste, bitte hier beginnen.'),
            'welcomeMessage' => $settings->get('organisation.visitor_experience.welcome_message'),
            'typeQuestion' => $settings->get('organisation.visitor_experience.visitor_type_question') ?: __('Was führt Sie zu uns?'),
            'instructions' => $settings->get('organisation.visitor_experience.visitor_instructions'),
            // Own key: upstream translates "Anmelden" as the staff "Login".
            'signInLabel' => config('organisation.visitor_experience.sign_in_label') ?: __('Jetzt anmelden'),
            'confirmation' => config('organisation.visitor_experience.sign_in_confirmation'),
            'types' => $this->step === 'welcome' ? $this->types() : [],
            'visitorType' => $this->typeSlug !== null ? $this->visitorType() : null,
            'fields' => $definition !== null ? $this->visibleFields($definition) : [],
            'policies' => $definition?->policies ?? [],
            'detailFields' => $this->typeSlug !== null ? VisitorDetails::visible($this->visitorType()) : [],
            'detailLabels' => $this->typeSlug !== null ? VisitorDetails::labels($this->visitorType()) : [],
            'detailRequired' => $this->typeSlug !== null ? VisitorDetails::required($this->visitorType()) : [],
            'returningEnabled' => $this->returningEnabled(),
            'returningPhoto' => in_array($this->step, ['details', 'confirm'], true) ? $this->returningPhotoUrl() : null,
            'savedPhotos' => $this->step === 'questions' ? $this->savedAnswerPhotos() : [],
            'identifyFields' => $this->typeSlug !== null ? array_values(array_intersect(['email', 'phone'], VisitorDetails::visible($this->visitorType()))) : [],
            'nameSignOut' => $this->nameSignOutEnabled(),
            'staffed' => $this->staffed(),
            'activeVisitName' => $this->step === 'welcome' ? $this->activeVisitName() : null,
            'signOutLabel' => config('organisation.visitor_experience.sign_out_label') ?: __('Besuch beenden'),
            'signOutConfirmation' => $settings->get('organisation.visitor_experience.sign_out_confirmation'),
        ])->layout('public.signin.layout', ['siteName' => $site->name, 'mode' => $this->mode()]);
    }

    /**
     * Time-of-day greeting in the site's timezone (the welcome screen polls, so it stays current).
     */
    private function greeting(Site $site): string
    {
        $hour = (int) now($site->timezone ?: config('app.timezone'))->format('G');

        return match (true) {
            $hour < 12 => __('Guten Morgen!'),
            $hour < 17 => __('Guten Tag!'),
            default => __('Guten Abend!'),
        };
    }

    /**
     * @return list<VisitorType>
     */
    private function types(): array
    {
        return VisitorType::query()->availableAt($this->site())->get()->all();
    }

    protected function visitorType(): VisitorType
    {
        $type = $this->typeSlug !== null ? collect($this->types())->firstWhere('slug', $this->typeSlug) : null;

        if ($type === null) {
            // The type was deactivated mid-registration: start over.
            $this->restart();
            abort(409);
        }

        return $type;
    }

    protected function definition(): WorkflowDefinition
    {
        try {
            return app(WorkflowResolver::class)->resolve($this->site(), $this->visitorType());
        } catch (InvalidArgumentException) {
            $this->restart();
            abort(409);
        }
    }

    /**
     * @return list<FormField>
     */
    private function visibleFields(WorkflowDefinition $definition): array
    {
        $visible = app(ConditionEvaluator::class)->visibleKeys($definition, $this->answers);

        return array_values(array_map(
            fn ($resolved) => tap($resolved->field, fn (FormField $f) => $f->setAttribute('is_required_here', $resolved->required)),
            array_filter($definition->fields, fn ($resolved) => in_array($resolved->field->key, $visible, true)),
        ));
    }

    /**
     * Answers ready for validation: signatures arrive as PNG data URLs from the drawing pad.
     *
     * @return array<string, mixed>
     */
    private function preparedAnswers(): array
    {
        $prepared = [];

        foreach ($this->answers as $key => $value) {
            // Signatures (drawing pad) and camera photos arrive as image data URLs.
            if (is_string($value) && preg_match('#^data:image/(png|jpeg);base64,#', $value) === 1) {
                $value = $this->dataUrlFile($value);
            }

            $prepared[$key] = $value;
        }

        return $prepared;
    }

    private function dataUrlFile(string $dataUrl): ?UploadedFile
    {
        $jpeg = str_starts_with($dataUrl, 'data:image/jpeg;');
        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);

        // Content is checked again by the answer rules (SafeRasterImage, size).
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $binary);

        return new UploadedFile($path, $jpeg ? 'photo.jpg' : 'signature.png', $jpeg ? 'image/jpeg' : 'image/png', null, true);
    }

    private function stepAfterHost(): string
    {
        $definition = $this->definition();

        return match (true) {
            $definition->fields !== [] => 'questions',
            $definition->policies !== [] => 'policies',
            default => 'confirm',
        };
    }

    private function stepApplies(string $step): bool
    {
        if ($this->typeSlug === null) {
            return $step === 'welcome';
        }

        $definition = $this->definition();

        return match ($step) {
            'identify' => $this->returningEnabled() && array_intersect(['email', 'phone'], VisitorDetails::visible($this->visitorType())) !== [],
            'host' => $this->visitorType()->host_requirement !== VisitorType::HOST_NONE,
            'questions' => $definition->fields !== [],
            'policies' => $definition->policies !== [],
            'done' => false,
            default => true,
        };
    }

    private function addErrorsUnder(string $prefix, ValidationException $e): void
    {
        foreach ($e->errors() as $key => $messages) {
            $this->addError($prefix.'.'.$key, $messages[0]);
        }
    }

    /**
     * A final-submit failure sends the visitor back to the step that needs fixing.
     */
    private function returnToStepFor(ValidationException $e): void
    {
        $keys = array_keys($e->errors());
        $first = $keys[0] ?? '';

        $this->step = match (true) {
            in_array($first, VisitorDetails::FIELDS, true) => 'details',
            $first === 'host' => 'host',
            str_starts_with($first, 'answers.') => 'questions',
            str_starts_with($first, 'policies.') => 'policies',
            default => 'welcome',
        };

        if ($this->step === 'details') {
            $this->addErrorsUnder('details', $e);
        } elseif (str_starts_with($first, 'policies.')) {
            // Policy errors are keyed by policy id server-side; show one message here.
            $this->addError('policies', __('Bitte bestätigen Sie alle Richtlinien.'));
        } else {
            $this->setErrorBag($e->validator->errors());
        }

        if ($this->step === 'welcome') {
            $this->addError('submit', $e->validator->errors()->first());
        }
    }
}
