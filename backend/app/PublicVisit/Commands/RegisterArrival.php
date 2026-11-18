<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Commands;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\HostDirectory\HostDirectory;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\PublicVisit\AttendanceTokens;
use App\PublicVisit\VisitorDetails;
use App\Services\VisitActionService;
use App\Services\WalkInVisitService;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\ResponseValidator;
use App\VisitorWorkflow\WorkflowRecorder;
use App\VisitorWorkflow\WorkflowResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The one implementation of public visitor sign-in, shared by kiosk and QR/mobile.
 *
 * Order: replay check → validate everything → one transaction (visitor, walk-in visit,
 * attendance, workflow answers and policy acceptances, idempotency record, departure token)
 * → check-in. Check-in runs after commit so a notification failure can never undo the
 * registration; a check-in that did not happen is retried on replay.
 */
class RegisterArrival
{
    public function __construct(
        private readonly WorkflowResolver $resolver,
        private readonly ResponseValidator $validator,
        private readonly WorkflowRecorder $recorder,
        private readonly HostDirectory $hosts,
        private readonly WalkInVisitService $walkIns,
        private readonly VisitActionService $visitActions,
        private readonly AttendanceTokens $tokens,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException with keys details.*, host, answers.*, policies.*, visitor_type, submission
     */
    public function handle(ArrivalRequest $request): ArrivalResult
    {
        $keyHash = $this->submissionKeyHash($request);

        if ($replay = $this->replay($request, $keyHash)) {
            return $replay;
        }

        try {
            $definition = $this->resolver->resolve($request->site, $request->visitorType);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['visitor_type' => __('Dieser Besuchertyp ist hier nicht verfügbar.')]);
        }

        $details = $this->validateDetails($request->details, $request->visitorType);
        $host = $this->resolveHost($request);
        $workflow = $this->validator->validate($definition, $request->answers, $request->acceptedPolicyVersionIds);

        try {
            [$visit, $visitor, $token] = DB::transaction(function () use ($request, $details, $host, $workflow, $keyHash): array {
                $visitor = Visitor::query()->create([...$details, 'created_by_user_id' => null]);

                $visit = $this->walkIns->createVisit(
                    $visitor,
                    null,
                    $host->getKey(),
                    $request->site->getKey(),
                    (bool) config('privacy.walk_in_confidential_default', true),
                    __('Selbst angemeldet (:channel).', ['channel' => $request->channel->value]),
                );

                $visit->visitors()->attach($visitor->getKey());

                // Written directly: upstream's pivot model only mass-assigns its own columns.
                DB::table('visit_visitor')
                    ->where('visit_id', $visit->getKey())
                    ->where('visitor_id', $visitor->getKey())
                    ->update(['checked_in_via' => $request->channel->value, 'kiosk_id' => $request->kioskId]);

                $this->recorder->record($visit, $visitor, $workflow, $request->channel->value);

                DB::table('public_submissions')->insert([
                    'key_hash' => $keyHash,
                    'channel' => $request->channel->value,
                    'visit_id' => $visit->getKey(),
                    'visitor_id' => $visitor->getKey(),
                    'created_at' => now(),
                ]);

                return [$visit, $visitor, $this->tokens->issue($visit, $visitor)];
            });
        } catch (QueryException $exception) {
            // A concurrent request with the same key won the race: return its result.
            if ($replay = $this->replay($request, $keyHash)) {
                return $replay;
            }

            throw $exception;
        }

        $this->checkIn($visit, $visitor);

        $this->audit->record('public.arrival.registered', subject: $visit, site: $request->site, context: [
            'channel' => $request->channel->value,
            'visitor_id' => $visitor->getKey(),
            'visitor_type' => $request->visitorType->slug,
            'host_source' => $request->hostToken !== null ? 'selected' : 'reception_fallback',
        ]);

        return new ArrivalResult($token, (string) $visitor->first_name, $this->displayName($host), false, $visit->getKey(), $visitor->getKey());
    }

    private function submissionKeyHash(ArrivalRequest $request): string
    {
        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $request->submissionKey) !== 1) {
            throw ValidationException::withMessages(['submission' => __('Die Anfrage ist ungültig. Bitte erneut versuchen.')]);
        }

        // Scoped to site and channel so keys cannot collide across devices or be probed elsewhere.
        return hash('sha256', $request->site->getKey().'|'.$request->channel->value.'|'.$request->submissionKey);
    }

    private function replay(ArrivalRequest $request, string $keyHash): ?ArrivalResult
    {
        $previous = DB::table('public_submissions')->where('key_hash', $keyHash)->first();

        if ($previous === null) {
            return null;
        }

        $visit = Visit::query()->with('host')->find($previous->visit_id);
        $visitor = Visitor::query()->find($previous->visitor_id);

        if ($visit === null || $visitor === null) {
            return null;
        }

        $this->checkIn($visit, $visitor);

        // The first response may never have reached the device, so issue a fresh token.
        return new ArrivalResult(
            $this->tokens->issue($visit, $visitor),
            (string) $visitor->first_name,
            $this->displayName($visit->host),
            true,
            $visit->getKey(),
            $visitor->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{first_name: string, name: string, email: ?string, phone: ?string, company: ?string}
     */
    private function validateDetails(array $details, VisitorType $type): array
    {
        return VisitorDetails::validate($details, $type);
    }

    private function resolveHost(ArrivalRequest $request): User
    {
        $requirement = $request->visitorType->host_requirement;

        if ($request->hostToken !== null && $requirement !== VisitorType::HOST_NONE) {
            $host = $this->hosts->resolve($request->site, $request->hostToken);

            if ($host === null) {
                throw ValidationException::withMessages(['host' => __('Bitte wählen Sie Ihre Ansprechperson erneut aus.')]);
            }

            return $host;
        }

        if ($requirement === VisitorType::HOST_REQUIRED) {
            throw ValidationException::withMessages(['host' => __('Bitte wählen Sie aus, wen Sie besuchen.')]);
        }

        $receptionId = $this->settings->forSite($request->site)->get('organisation.reception_host_user_id');
        $reception = $receptionId ? User::query()->where('is_active', true)->find($receptionId) : null;

        if ($reception === null) {
            // A configuration gap, not a visitor error: say so in the log, keep the screen generic.
            Log::channel('web')->warning('public_arrival_missing_reception_host', ['site_id' => $request->site->getKey()]);

            throw ValidationException::withMessages(['host' => __('Die Anmeldung ist hier gerade nicht möglich. Bitte wenden Sie sich an den Empfang.')]);
        }

        return $reception;
    }

    private function checkIn(Visit $visit, Visitor $visitor): void
    {
        try {
            $this->visitActions->checkInParticipant($visit, $visitor, null);
        } catch (Throwable $exception) {
            $checkedIn = DB::table('visit_visitor')
                ->where('visit_id', $visit->getKey())
                ->where('visitor_id', $visitor->getKey())
                ->whereNotNull('checked_in_at')
                ->exists();

            // Checked in, but the host notification failed: the visitor stays signed in (brief §25).
            if ($checkedIn) {
                report($exception);
                $this->audit->record('public.arrival.notification_failed', subject: $visit, site: $visit->site_id, result: AuditEvent::RESULT_FAILURE);

                return;
            }

            throw $exception;
        }
    }

    private function displayName(?User $host): string
    {
        return $host ? trim($host->first_name.' '.$host->name) : '';
    }
}
