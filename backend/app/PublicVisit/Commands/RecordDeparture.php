<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Commands;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\Visit;
use App\Models\Visitor;
use App\PublicVisit\ActiveAttendances;
use App\PublicVisit\AttendanceTokens;
use App\PublicVisit\Channel;
use App\Services\VisitActionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self sign-out with a departure token. Every failure (unknown, expired, used, already signed
 * out, visit closed) gets the same message, so the response reveals nothing about the token.
 */
class RecordDeparture
{
    public function __construct(
        private readonly AttendanceTokens $tokens,
        private readonly VisitActionService $visitActions,
        private readonly AuditLogger $audit,
        private readonly ActiveAttendances $attendances,
    ) {}

    /**
     * @return string the visitor's first name, for the goodbye screen
     *
     * @throws ValidationException key "token"
     */
    public function handle(string $token, Channel $channel): string
    {
        $row = strlen($token) === 40 ? $this->tokens->find($token) : null;
        $visit = $row ? Visit::query()->find($row->visit_id) : null;
        $visitor = $row ? Visitor::query()->find($row->visitor_id) : null;

        if ($visit === null || $visitor === null || ! $this->attendances->isActive($visit, $visitor)) {
            // Close any token that can no longer be used (e.g. staff already signed the visitor out).
            if ($row !== null) {
                $this->tokens->invalidateFor($row->visit_id, $row->visitor_id);
            }

            $this->reject($channel);
        }

        return $this->depart($visit, $visitor, $channel);
    }

    /**
     * Sign-out of an attendance the screen located without a token (kiosk "End visit": the
     * visitor named themselves and confirmed). The caller resolved it via ActiveAttendances.
     *
     * @throws ValidationException key "token"
     */
    public function handleAttendance(Visit $visit, Visitor $visitor, Channel $channel): string
    {
        if (! $this->attendances->isActive($visit, $visitor)) {
            $this->reject($channel);
        }

        return $this->depart($visit, $visitor, $channel);
    }

    /**
     * Whether a departure token still belongs to an active attendance at the site (the phone
     * shows "Sign out" only then). Does not use the token.
     */
    public function tokenIsActiveAt(string $token, int $siteId): ?string
    {
        $row = strlen($token) === 40 ? $this->tokens->find($token) : null;
        $visit = $row ? Visit::query()->find($row->visit_id) : null;
        $visitor = $row ? Visitor::query()->find($row->visitor_id) : null;

        return $visit !== null && $visitor !== null && $visit->site_id === $siteId && $this->attendances->isActive($visit, $visitor)
            ? (string) $visitor->first_name
            : null;
    }

    private function reject(Channel $channel): never
    {
        $this->audit->record('public.departure.rejected', result: AuditEvent::RESULT_DENIED, context: ['channel' => $channel->value]);

        throw ValidationException::withMessages(['token' => __('Dieser Abmeldelink ist nicht mehr gültig. Bitte melden Sie sich am Empfang ab.')]);
    }

    private function depart(Visit $visit, Visitor $visitor, Channel $channel): string
    {
        DB::transaction(function () use ($visit, $visitor, $channel): void {
            $this->visitActions->checkOutParticipant($visit, $visitor, null);

            DB::table('visit_visitor')
                ->where('visit_id', $visit->getKey())
                ->where('visitor_id', $visitor->getKey())
                ->update(['checked_out_via' => $channel->value]);

            $this->tokens->invalidateFor($visit->getKey(), $visitor->getKey());
        });

        $this->audit->record('public.departure.recorded', subject: $visit, site: $visit->site_id, context: [
            'channel' => $channel->value,
            'visitor_id' => $visitor->getKey(),
        ]);

        return (string) $visitor->first_name;
    }
}
