<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Audit\AuditLogger;
use App\VisitorWorkflow\Console\PurgeEvidenceFiles;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class VisitorWorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([VisitorType::class, FormField::class, Policy::class] as $model) {
            Gate::policy($model, VisitorWorkflowPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeEvidenceFiles::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('visitorportal:evidence:purge')->dailyAt('03:50')->withoutOverlapping();
        });

        // Configuration changes are audited (brief §23: configuration and policy changes).
        foreach ([VisitorType::class => 'visitor_type', FormField::class => 'form_field', Policy::class => 'policy'] as $model => $name) {
            $model::created(fn (Model $m) => $this->audit()->record("workflow.{$name}.created", subject: $m));
            $model::updated(function (Model $m) use ($name): void {
                $fields = array_values(array_diff(array_keys($m->getChanges()), ['updated_at']));

                if ($fields !== []) {
                    $this->audit()->record("workflow.{$name}.updated", subject: $m, context: ['fields' => $fields]);
                }
            });
            $model::deleted(fn (Model $m) => $this->audit()->record("workflow.{$name}.deleted", subject: $m));
        }

        PolicyVersion::created(fn (PolicyVersion $v) => $this->audit()->record('workflow.policy.version_published', subject: $v, context: [
            'policy_id' => $v->policy_id,
            'version' => $v->version,
            'content_hash' => $v->content_hash,
        ]));
    }

    private function audit(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }
}
