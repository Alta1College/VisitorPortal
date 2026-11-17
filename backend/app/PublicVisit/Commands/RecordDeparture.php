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

        $active = $visit !== null && $visitor !== null
            && $this->visitActions->canOperate($visit)
            && DB::table('visit_visitor')
                ->where('visit_id', $visit->getKey())
                ->where('visitor_id', $visitor->getKey())
                ->whereNotNull('checked_in_at')
                ->whereNull('checked_out_at')
                ->exists();

        if (! $active) {
            // Close any token that can no longer be used (e.g. staff already signed the visitor out).
            if ($row !== null) {
                $this->tokens->invalidateFor($row->visit_id, $row->visitor_id);
            }

            $this->audit->record('public.departure.rejected', result: AuditEvent::RESULT_DENIED, context: ['channel' => $channel->value]);

            throw ValidationException::withMessages(['token' => __('Dieser Abmeldelink ist nicht mehr gültig. Bitte melden Sie sich am Empfang ab.')]);
        }

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
