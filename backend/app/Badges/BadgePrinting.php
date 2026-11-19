<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Badges\Jobs\PrintBadge;
use App\Kiosk\Kiosk;
use App\Models\Site;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitActionService;
use App\VisitorWorkflow\Models\VisitorType;
use Throwable;

/**
 * Prints badges straight to network label printers (no print dialog). Sign-in only queues the
 * job: a printer that is off, out of labels or unreachable never affects the sign-in itself
 * (brief §25); failures are audited and shown on the printer in admin.
 */
class BadgePrinting
{
    public function __construct(
        private readonly BadgeRenderer $renderer,
        private readonly PrinterTransport $transport,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The printer for a sign-in: the kiosk's own printer, otherwise the site's default printer
     * (used for QR / phone sign-ins).
     */
    public function printerFor(Site $site, ?int $kioskId): ?BadgePrinter
    {
        $kioskPrinterId = $kioskId !== null ? Kiosk::query()->whereKey($kioskId)->value('badge_printer_id') : null;

        if ($kioskPrinterId !== null) {
            $printer = BadgePrinter::query()->enabled()->where('site_id', $site->getKey())->find($kioskPrinterId);

            if ($printer !== null) {
                return $printer;
            }
        }

        return BadgePrinter::query()->enabled()->where('site_id', $site->getKey())->where('is_site_default', true)->first();
    }

    /**
     * Queue the badge for a new sign-in. Returns whether a badge will be printed.
     */
    public function queueForArrival(Site $site, VisitorType $type, Visit $visit, Visitor $visitor, ?int $kioskId): bool
    {
        if (! $type->print_badge) {
            return false;
        }

        $printer = $this->printerFor($site, $kioskId);

        if ($printer === null) {
            return false;
        }

        PrintBadge::dispatch($visit->getKey(), $visitor->getKey(), $printer->getKey());

        return true;
    }

    /** @var array<int, bool> */
    private array $siteHasPrinter = [];

    /**
     * Whether reception can send badges to a label printer at this site (reprint button).
     */
    public function siteHasPrinter(?int $siteId): bool
    {
        if ($siteId === null) {
            return false;
        }

        return $this->siteHasPrinter[$siteId] ??= BadgePrinter::query()->enabled()->where('site_id', $siteId)->exists();
    }

    /**
     * Reception reprint: the site's default printer, or its only enabled printer.
     */
    public function reprint(Visit $visit, Visitor $visitor, ?int $userId): ?BadgePrinter
    {
        $printers = BadgePrinter::query()->enabled()->where('site_id', $visit->site_id)->orderByDesc('is_site_default')->get();
        $printer = $printers->firstWhere('is_site_default', true) ?? ($printers->count() === 1 ? $printers->first() : null);

        if ($printer === null) {
            return null;
        }

        PrintBadge::dispatch($visit->getKey(), $visitor->getKey(), $printer->getKey());
        $this->audit->record('badge.reprint_requested', subject: $visit, site: $visit->site_id, context: ['visitor_id' => $visitor->getKey(), 'printer_id' => $printer->getKey()], actorUserId: $userId);

        return $printer;
    }

    /**
     * @throws BadgePrintException
     */
    public function printAttendance(Visit $visit, Visitor $visitor, BadgePrinter $printer): void
    {
        $this->send($printer, BadgeContent::forAttendance($visit, $visitor));

        try {
            app(VisitActionService::class)->printBadge($visit, $visitor);
        } catch (Throwable) {
            // The visit may have ended meanwhile; the badge still printed.
        }

        $this->audit->record('badge.printed', subject: $visit, site: $visit->site_id, context: ['visitor_id' => $visitor->getKey(), 'printer_id' => $printer->getKey()]);
    }

    /**
     * Admin "Test print": a sample badge, sent now so the result shows at once.
     *
     * @throws BadgePrintException
     */
    public function printSample(BadgePrinter $printer, ?int $userId): void
    {
        try {
            $this->send($printer, BadgeContent::sample($printer->site));
        } catch (BadgePrintException $e) {
            $this->recordFailure($printer, $e->getMessage(), null, null);

            throw $e;
        }

        $this->audit->record('badge.test_printed', subject: $printer, site: $printer->site_id, actorUserId: $userId);
    }

    /**
     * The sample badge exactly as it prints (black and white, dithered), for the admin preview.
     */
    public function previewPng(BadgePrinter $printer): string
    {
        return Bitmap::fromPng($this->renderer->png(BadgeContent::sample($printer->site), $this->formatOf($printer)))->toPng();
    }

    public function recordFailure(BadgePrinter $printer, string $message, ?Visit $visit, ?int $visitorId): void
    {
        $printer->forceFill(['last_failed_at' => now(), 'last_error' => mb_substr($message, 0, 255)])->save();

        $this->audit->record('badge.print_failed', subject: $visit ?? $printer, site: $printer->site_id, result: AuditEvent::RESULT_FAILURE, context: array_filter([
            'printer_id' => $printer->getKey(),
            'visitor_id' => $visitorId,
            'error' => mb_substr($message, 0, 255),
        ]));
    }

    /**
     * @throws BadgePrintException
     */
    private function send(BadgePrinter $printer, BadgeContent $content): void
    {
        $format = $this->formatOf($printer);
        $driver = $printer->driverInstance() ?? throw new BadgePrintException(__('Unbekannter Druckertyp „:driver“.', ['driver' => $printer->driver]));

        $bitmap = Bitmap::fromPng($this->renderer->png($content, $format));
        $this->transport->send($printer, $driver->encode($bitmap, $format));

        $printer->forceFill(['last_printed_at' => now(), 'last_error' => null])->save();
    }

    private function formatOf(BadgePrinter $printer): LabelFormat
    {
        return $printer->format() ?? throw new BadgePrintException(__('Unbekanntes Etikettenformat „:label“.', ['label' => $printer->label]));
    }
}
