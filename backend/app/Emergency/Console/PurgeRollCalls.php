<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Console;

use App\Audit\AuditLogger;
use App\Emergency\RollCall;
use App\Settings\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Roll call lists hold visitor names: remove ended roll calls after the visit retention period
 * (their audit events remain for the audit retention period).
 */
class PurgeRollCalls extends Command
{
    protected $signature = 'visitorportal:roll-calls:purge {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Delete ended roll calls older than the visit retention period.';

    public function handle(SettingsRepository $settings, AuditLogger $audit): int
    {
        $days = max(1, (int) ($settings->organisationValue('privacy.visit_retention_days') ?: config('privacy.visit_retention_days', 365)));
        $query = RollCall::query()->whereNotNull('ended_at')->where('ended_at', '<', now()->subDays($days));
        $count = $query->count();

        if (! $this->option('dry-run') && $count > 0) {
            $query->delete();
            $audit->record('retention.roll_calls.purged', context: ['roll_calls' => $count, 'days' => $days]);
        }

        $this->info(sprintf('%s %d roll call(s) older than %d days.', $this->option('dry-run') ? 'Would delete' : 'Deleted', $count, $days));

        return self::SUCCESS;
    }
}
