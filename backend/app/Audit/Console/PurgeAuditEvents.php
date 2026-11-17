<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Audit\Console;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeAuditEvents extends Command
{
    protected $signature = 'visitorportal:audit:purge {--dry-run : Count expired audit events without deleting them}';

    protected $description = 'Delete audit events older than the configured audit retention period.';

    public function handle(AuditLogger $audit): int
    {
        $days = max(30, (int) config('organisation.audit_retention_days', 1095));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('audit_events')->where('occurred_at', '<', $cutoff);
        $matched = (clone $query)->count();
        $deleted = 0;

        if (! $dryRun) {
            // Bulk delete through the query builder: the retention purge is the only path
            // that removes audit rows. The model itself refuses deletes.
            do {
                $batch = (clone $query)->orderBy('id')->limit(1000)->pluck('id');
                $deleted += $batch->isEmpty() ? 0 : DB::table('audit_events')->whereIn('id', $batch)->delete();
            } while ($batch->isNotEmpty());
        }

        $audit->record('retention.audit.purged', context: [
            'dry_run' => $dryRun,
            'retention_days' => $days,
            'cutoff_at' => $cutoff->toIso8601String(),
            'matched' => $matched,
            'deleted' => $deleted,
        ], result: AuditEvent::RESULT_SUCCESS);

        $this->info(sprintf('%s %d audit event(s) older than %d days.', $dryRun ? 'Would delete' : 'Deleted', $dryRun ? $matched : $deleted, $days));

        return self::SUCCESS;
    }
}
