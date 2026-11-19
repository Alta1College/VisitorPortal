<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Audit\AuditLogger;
use App\Models\Site;
use App\PublicVisit\AttendanceTokens;
use App\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-site automatic sign-out (setting "Sign visitors out automatically at", site timezone).
 * Anyone still signed in from before the most recent cut-off is signed out, recorded as
 * "auto". Never while a roll call is open at the site: an emergency list must not change.
 */
class AutoSignOut
{
    public const CHANNEL = 'auto';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AttendanceTokens $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int number of visitors signed out across all sites
     */
    public function run(): int
    {
        $total = 0;

        foreach (Site::query()->where('is_active', true)->get() as $site) {
            $total += $this->runForSite($site);
        }

        return $total;
    }

    public function runForSite(Site $site): int
    {
        $cutoff = $this->latestCutoff($site);

        if ($cutoff === null || RollCall::query()->where('site_id', $site->getKey())->whereNull('ended_at')->exists()) {
            return 0;
        }

        $attendances = DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->where('visits.site_id', $site->getKey())
            ->whereNotNull('visit_visitor.checked_in_at')
            ->whereNull('visit_visitor.checked_out_at')
            // Timestamps are stored in the app timezone.
            ->where('visit_visitor.checked_in_at', '<', $cutoff->copy()->timezone((string) config('app.timezone')))
            ->get(['visit_visitor.visit_id', 'visit_visitor.visitor_id']);

        foreach ($attendances as $attendance) {
            $updated = DB::table('visit_visitor')
                ->where('visit_id', $attendance->visit_id)
                ->where('visitor_id', $attendance->visitor_id)
                ->whereNull('checked_out_at')
                ->update(['checked_out_at' => now(), 'checked_out_via' => self::CHANNEL, 'updated_at' => now()]);

            if ($updated > 0) {
                $this->tokens->invalidateFor((int) $attendance->visit_id, (int) $attendance->visitor_id);
            }
        }

        if ($attendances->isNotEmpty()) {
            $this->audit->record('attendance.auto_signed_out', site: $site, context: [
                'cutoff' => $cutoff->format('Y-m-d H:i'),
                'visitors' => $attendances->count(),
            ]);
        }

        return $attendances->count();
    }

    /**
     * The most recent cut-off moment at or before now in the site's timezone, or null if the
     * site has no automatic sign-out.
     */
    public function latestCutoff(Site $site): ?Carbon
    {
        $time = (string) $this->settings->forSite($site)->get('organisation.visitor_experience.auto_sign_out_time');

        if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time, $parts) !== 1) {
            return null;
        }

        $now = now($site->timezone ?: (string) config('app.timezone'));
        $cutoff = $now->copy()->setTime((int) $parts[1], (int) $parts[2]);

        return $cutoff->gt($now) ? $cutoff->subDay() : $cutoff;
    }
}
