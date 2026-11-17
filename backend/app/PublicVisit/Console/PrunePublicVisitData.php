<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency records are only needed while a retry is plausible; spent tokens only for a short
 * look-back. Neither is history (the audit trail and attendance columns are).
 */
class PrunePublicVisitData extends Command
{
    protected $signature = 'visitorportal:public:prune';

    protected $description = 'Delete old public submission keys and expired or used attendance tokens.';

    public function handle(): int
    {
        $submissions = DB::table('public_submissions')->where('created_at', '<', now()->subDays(2))->delete();

        $tokens = DB::table('attendance_tokens')
            ->where(fn ($q) => $q
                ->where('expires_at', '<', now()->subDays(7))
                ->orWhere('used_at', '<', now()->subDays(7)))
            ->delete();

        $this->info("Deleted {$submissions} submission key(s) and {$tokens} attendance token(s).");

        return self::SUCCESS;
    }
}
