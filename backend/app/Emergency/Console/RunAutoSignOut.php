<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Console;

use App\Emergency\AutoSignOut;
use Illuminate\Console\Command;

class RunAutoSignOut extends Command
{
    protected $signature = 'visitorportal:auto-sign-out';

    protected $description = 'Sign out visitors still signed in after each site\'s automatic sign-out time.';

    public function handle(AutoSignOut $autoSignOut): int
    {
        $count = $autoSignOut->run();
        $this->info($count.' visitor(s) signed out automatically.');

        return self::SUCCESS;
    }
}
