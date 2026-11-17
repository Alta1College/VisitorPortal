<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\VisitorWorkflow\Models\FormField;

/**
 * Conditional visibility. A field's conditions look like
 * {"match": "all"|"any", "rules": [{"field": "is_contractor", "operator": "equals", "value": "1"}]}.
 * Rules may only refer to fields asked earlier; an answer to a hidden field counts as empty.
 */
final class ConditionEvaluator
{
    public const OPERATORS = ['equals', 'not_equals', 'in', 'filled', 'empty'];

    /**
     * Keys of fields that are visible given the answers, in workflow order.
     *
     * @param  array<string, mixed>  $answers  keyed by field key
     * @return list<string>
     */
    public function visibleKeys(WorkflowDefinition $definition, array $answers): array
    {
        $visible = [];
        $effective = [];

        foreach ($definition->fields as $resolved) {
            $key = $resolved->field->key;

            if ($this->isVisible($resolved->field, $effective)) {
                $visible[] = $key;
                $effective[$key] = $answers[$key] ?? null;
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $earlierAnswers  answers of earlier visible fields only
     */
    public function isVisible(FormField $field, array $earlierAnswers): bool
    {
        $rules = $field->conditions['rules'] ?? [];

        if ($rules === []) {
            return true;
        }

        $results = array_map(fn (array $rule): bool => $this->matches($rule, $earlierAnswers), $rules);

        return ($field->conditions['match'] ?? 'all') === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array{field: string, operator: string, value?: mixed}  $rule
     * @param  array<string, mixed>  $answers
     */
    private function matches(array $rule, array $answers): bool
    {
        $answer = $this->normalise($answers[$rule['field']] ?? null);
        $expected = $rule['value'] ?? null;

        return match ($rule['operator']) {
            'equals' => $answer === $this->normalise($expected),
            'not_equals' => $answer !== $this->normalise($expected),
            'in' => in_array($answer, array_map(
                fn ($v) => $this->normalise(is_string($v) ? trim($v) : $v),
                is_string($expected) ? explode(',', $expected) : (array) $expected,
            ), true),
            'filled' => $answer !== null,
            'empty' => $answer === null,
            default => false,
        };
    }

    private function normalise(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '1', // an uploaded file counts as filled
        };
    }
}
