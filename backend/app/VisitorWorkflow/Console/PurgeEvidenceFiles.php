<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Console;

use App\Audit\AuditLogger;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes evidence files (photos, signatures, uploads) after the evidence retention period,
 * and any evidence file no longer referenced by an answer. The second sweep matters because
 * upstream's visit purge deletes rows in bulk, which removes answers without touching files.
 */
class PurgeEvidenceFiles extends Command
{
    protected $signature = 'visitorportal:evidence:purge {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Delete visitor evidence files past the evidence retention period, and orphaned evidence files.';

    public function handle(AuditLogger $audit): int
    {
        $days = max(1, (int) config('organisation.evidence_retention_days', 365));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);

        $expired = 0;

        VisitResponse::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($responses) use ($disk, $dryRun, &$expired): void {
                foreach ($responses as $response) {
                    $expired++;

                    if (! $dryRun) {
                        $disk->delete($response->file_path);
                        $response->forceFill(['file_path' => null])->save();
                    }
                }
            });

        // Orphans: files with no answer pointing at them. Skip files from the last hour, which
        // may belong to a registration that is still being saved.
        $referenced = VisitResponse::query()->whereNotNull('file_path')->pluck('file_path')->flip();
        $orphans = 0;

        foreach ($disk->allFiles(WorkflowRecorder::EVIDENCE_DIRECTORY) as $path) {
            if ($referenced->has($path) || $disk->lastModified($path) > now()->subHour()->getTimestamp()) {
                continue;
            }

            $orphans++;

            if (! $dryRun) {
                $disk->delete($path);
            }
        }

        $audit->record('retention.evidence.purged', context: [
            'dry_run' => $dryRun,
            'retention_days' => $days,
            'expired_files' => $expired,
            'orphaned_files' => $orphans,
        ]);

        $this->info(sprintf('%s %d expired and %d orphaned evidence file(s).', $dryRun ? 'Would delete' : 'Deleted', $expired, $orphans));

        return self::SUCCESS;
    }
}
