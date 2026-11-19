<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges\Jobs;

use App\Badges\BadgePrinter;
use App\Badges\BadgePrinting;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Prints one visitor's badge. Holds ids only; retried briefly (a printer waking up or a VPN
 * blip), then recorded as failed. The visitor stays signed in either way.
 */
class PrintBadge implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public function __construct(
        public readonly int $visitId,
        public readonly int $visitorId,
        public readonly int $printerId,
    ) {}

    public function handle(BadgePrinting $printing): void
    {
        $visit = Visit::query()->with('host')->find($this->visitId);
        $visitor = Visitor::query()->find($this->visitorId);
        $printer = BadgePrinter::query()->find($this->printerId);

        if ($visit === null || $visitor === null || $printer === null || ! $printer->is_enabled) {
            return;
        }

        $printing->printAttendance($visit, $visitor, $printer);
    }

    public function failed(Throwable $exception): void
    {
        $printer = BadgePrinter::query()->find($this->printerId);

        if ($printer !== null) {
            report($exception);
            app(BadgePrinting::class)->recordFailure($printer, $exception->getMessage(), Visit::query()->find($this->visitId), $this->visitorId);
        }
    }
}
