<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Audit;

use App\Models\Site;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records meaningful actions to the audit_events table. Recording never throws: a failure is
 * reported and logged, so an audit problem cannot undo the action being audited.
 */
class AuditLogger
{
    public function __construct(private readonly Auth $auth) {}

    /**
     * @param  array<string, mixed>  $context  Non-sensitive detail (changed keys, counts). Never secrets.
     */
    public function record(
        string $action,
        ?Model $subject = null,
        Site|int|null $site = null,
        string $result = AuditEvent::RESULT_SUCCESS,
        array $context = [],
        ?int $actorUserId = null,
    ): ?AuditEvent {
        try {
            return AuditEvent::query()->create([
                'occurred_at' => now(),
                'action' => $action,
                'result' => $result,
                'channel' => $this->channel(),
                'actor_user_id' => $actorUserId ?? $this->currentUserId(),
                'site_id' => $site instanceof Site ? $site->getKey() : $site,
                'subject_type' => $subject !== null ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'ip_hash' => $this->ipHash(),
                'context' => $context !== [] ? $context : null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            Log::channel('web')->error('audit_event_not_recorded', ['action' => $action]);

            return null;
        }
    }

    private function currentUserId(): ?int
    {
        $id = $this->auth->guard()->id();

        return $id !== null ? (int) $id : null;
    }

    private function channel(): string
    {
        return app()->runningInConsole() && ! app()->runningUnitTests() ? 'console' : 'web';
    }

    private function ipHash(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $ip = request()->ip();

        // Same keyed-hash approach upstream uses for security log entries: correlatable, not reversible.
        return $ip !== null ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }
}
