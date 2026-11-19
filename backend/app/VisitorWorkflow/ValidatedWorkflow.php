<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

/**
 * Output of ResponseValidator: only answers to visible fields, normalised. Policy acceptance
 * has been checked, so every policy in the definition is accepted.
 */
final class ValidatedWorkflow
{
    /**
     * @param  array<string, mixed>  $answers  field key => string value or UploadedFile
     */
    public function __construct(
        public readonly WorkflowDefinition $definition,
        public readonly array $answers,
    ) {}
}
