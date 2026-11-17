<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Audit\AuditLogger;
use App\Models\Visit;
use App\Models\Visitor;
use App\VisitorWorkflow\Models\PolicyAcceptance;
use App\VisitorWorkflow\Models\VisitResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Stores a validated workflow for one attendance (visit + visitor): visitor type on the
 * attendance, answers (sensitive ones encrypted, files on the private disk) and policy
 * acceptances, all in one transaction. Used by every registration channel.
 */
class WorkflowRecorder
{
    public const EVIDENCE_DISK = 'local';

    public const EVIDENCE_DIRECTORY = 'evidence';

    public function __construct(private readonly AuditLogger $audit) {}

    public function record(Visit $visit, Visitor $visitor, ValidatedWorkflow $workflow, string $channel): void
    {
        $definition = $workflow->definition;

        if ((int) $visit->site_id !== (int) $definition->site->getKey()) {
            throw new InvalidArgumentException('Workflow site does not match the visit site.');
        }

        $attendance = DB::table('visit_visitor')->where('visit_id', $visit->getKey())->where('visitor_id', $visitor->getKey());

        if (! $attendance->exists()) {
            throw new InvalidArgumentException('Visitor is not attached to this visit.');
        }

        $storedFiles = [];

        try {
            DB::transaction(function () use ($visit, $visitor, $workflow, $definition, $channel, $attendance, &$storedFiles): void {
                $attendance->update(['visitor_type_id' => $definition->visitorType->getKey()]);

                foreach ($workflow->answers as $key => $answer) {
                    $field = $definition->field($key)->field;
                    $filePath = null;
                    $value = null;

                    if ($answer instanceof UploadedFile) {
                        $filePath = $this->storeFile($visit, $answer);
                        $storedFiles[] = $filePath;
                        $value = $answer->getClientOriginalName() !== '' ? Str::limit($answer->getClientOriginalName(), 180, '') : null;
                    } else {
                        $value = (string) $answer;
                    }

                    VisitResponse::query()->updateOrCreate(
                        ['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey(), 'field_key' => $key],
                        [
                            'form_field_id' => $field->getKey(),
                            'field_label' => $field->label,
                            'field_type' => $field->type,
                            'is_sensitive' => $field->is_sensitive,
                            'value' => VisitResponse::storableValue($value, $field->is_sensitive),
                            'file_path' => $filePath,
                        ],
                    );
                }

                foreach ($definition->policies as $version) {
                    PolicyAcceptance::query()->firstOrCreate(
                        ['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey(), 'policy_version_id' => $version->getKey()],
                        ['accepted_at' => now(), 'channel' => $channel],
                    );
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $path) {
                Storage::disk(self::EVIDENCE_DISK)->delete($path);
            }

            throw $exception;
        }

        // Counts only: answers can be personal or sensitive and do not belong in the audit log.
        $this->audit->record('visit.workflow.recorded', subject: $visit, site: $visit->site_id, context: [
            'visitor_id' => $visitor->getKey(),
            'visitor_type' => $definition->visitorType->slug,
            'channel' => $channel,
            'answers' => count($workflow->answers),
            'files' => count($storedFiles),
            'policy_versions' => array_map(fn ($v) => $v->getKey(), $definition->policies),
        ]);
    }

    private function storeFile(Visit $visit, UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $path = $file->storeAs(
            self::EVIDENCE_DIRECTORY.'/'.$visit->getKey(),
            Str::uuid()->toString().'.'.$extension,
            ['disk' => self::EVIDENCE_DISK],
        );

        if ($path === false) {
            throw new \RuntimeException('Evidence file could not be stored.');
        }

        return $path;
    }
}
