<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Audit;

use App\Audit\Console\PurgeAuditEvents;
use App\Models\DataRetentionRun;
use App\Models\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Audit trail wiring that needs no edits to upstream code: model observers, the retention
 * purge command and its schedule.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeAuditEvents::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('visitorportal:audit:purge')->dailyAt('03:40')->withoutOverlapping();
        });

        $this->auditSiteChanges();
        $this->auditRetentionRuns();
    }

    private function auditSiteChanges(): void
    {
        Site::created(fn (Site $site) => $this->audit()->record('site.created', subject: $site, site: $site));

        Site::updated(function (Site $site): void {
            $fields = array_values(array_diff(array_keys($site->getChanges()), ['updated_at']));

            if ($fields !== []) {
                $this->audit()->record('site.updated', subject: $site, site: $site, context: ['fields' => $fields]);
            }
        });

        Site::deleted(fn (Site $site) => $this->audit()->record('site.deleted', subject: $site, site: $site));
    }

    private function auditRetentionRuns(): void
    {
        // Upstream's visit purge writes a data_retention_runs row and finishes it with a final
        // status. Record the outcome without changing the upstream command.
        DataRetentionRun::updated(function (DataRetentionRun $run): void {
            $status = (string) $run->getAttribute('status');

            if (! $run->wasChanged('status') || ! in_array($status, ['completed', 'failed'], true)) {
                return;
            }

            $this->audit()->record(
                'retention.visits.purged',
                subject: $run,
                result: $status === 'completed' ? AuditEvent::RESULT_SUCCESS : AuditEvent::RESULT_FAILURE,
                context: array_filter([
                    'dry_run' => (bool) $run->getAttribute('dry_run'),
                    'retention_days' => $run->getAttribute('retention_days'),
                    'visits_deleted' => $run->getAttribute('visits_deleted'),
                    'visitors_deleted' => $run->getAttribute('visitors_deleted'),
                    'notifications_deleted' => $run->getAttribute('notifications_deleted'),
                ], fn ($value): bool => $value !== null),
            );
        });
    }

    private function audit(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }
}
