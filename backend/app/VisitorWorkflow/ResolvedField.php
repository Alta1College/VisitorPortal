<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\VisitorWorkflow\Models\FormField;

/**
 * A field as it applies to one site and visitor type: the definition plus the effective
 * required flag and position from the most specific matching assignment.
 */
final class ResolvedField
{
    public function __construct(
        public readonly FormField $field,
        public readonly bool $required,
        public readonly int $sortOrder,
    ) {}
}
