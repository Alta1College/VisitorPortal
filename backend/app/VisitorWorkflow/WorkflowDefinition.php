<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Models\Site;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;

/**
 * Everything a visitor of one type at one site is asked: ordered fields and the current
 * version of every policy that applies.
 */
final class WorkflowDefinition
{
    /**
     * @param  list<ResolvedField>  $fields
     * @param  list<PolicyVersion>  $policies
     */
    public function __construct(
        public readonly Site $site,
        public readonly VisitorType $visitorType,
        public readonly array $fields,
        public readonly array $policies,
    ) {}

    public function field(string $key): ?ResolvedField
    {
        foreach ($this->fields as $resolved) {
            if ($resolved->field->key === $key) {
                return $resolved;
            }
        }

        return null;
    }
}
