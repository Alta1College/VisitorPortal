<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Models\Site;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Works out which fields and policies apply to a visitor type at a site.
 *
 * Fields: of all active assignments matching the site and type (null = any), the most specific
 * wins (site + type > site > type > organisation-wide), so a site can make an organisation
 * field required or reorder it without duplicating the field.
 * Policies: every active policy with at least one matching assignment and a published version.
 */
class WorkflowResolver
{
    public function resolve(Site $site, VisitorType $visitorType): WorkflowDefinition
    {
        if (! $visitorType->isAvailableAt($site)) {
            throw new InvalidArgumentException('Visitor type is not available at this site.');
        }

        return new WorkflowDefinition($site, $visitorType, $this->fields($site, $visitorType), $this->policies($site, $visitorType));
    }

    /**
     * @return list<ResolvedField>
     */
    private function fields(Site $site, VisitorType $visitorType): array
    {
        $assignments = FormFieldAssignment::query()
            ->with('field')
            ->whereHas('field', fn (Builder $q) => $q->where('is_active', true))
            ->where(fn (Builder $q) => $q->whereNull('site_id')->orWhere('site_id', $site->getKey()))
            ->where(fn (Builder $q) => $q->whereNull('visitor_type_id')->orWhere('visitor_type_id', $visitorType->getKey()))
            ->get();

        /** @var array<int, FormFieldAssignment> $winners */
        $winners = [];

        foreach ($assignments as $assignment) {
            $current = $winners[$assignment->form_field_id] ?? null;

            if ($current === null || $this->specificity($assignment) > $this->specificity($current)) {
                $winners[$assignment->form_field_id] = $assignment;
            }
        }

        $resolved = array_map(
            fn (FormFieldAssignment $a): ResolvedField => new ResolvedField($a->field, $a->is_required, $a->sort_order),
            array_values($winners),
        );

        usort($resolved, fn (ResolvedField $a, ResolvedField $b): int => [$a->sortOrder, $a->field->getKey()] <=> [$b->sortOrder, $b->field->getKey()]);

        return $resolved;
    }

    /**
     * @return list<PolicyVersion>
     */
    private function policies(Site $site, VisitorType $visitorType): array
    {
        return Policy::query()
            ->where('is_active', true)
            ->whereHas('assignments', fn (Builder $q) => $q
                ->where(fn (Builder $s) => $s->whereNull('site_id')->orWhere('site_id', $site->getKey()))
                ->where(fn (Builder $t) => $t->whereNull('visitor_type_id')->orWhere('visitor_type_id', $visitorType->getKey())))
            ->whereHas('versions')
            ->with('currentVersion')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Policy $policy): PolicyVersion => $policy->currentVersion)
            ->values()
            ->all();
    }

    private function specificity(FormFieldAssignment $assignment): int
    {
        return ($assignment->site_id !== null ? 2 : 0) + ($assignment->visitor_type_id !== null ? 1 : 0);
    }
}
