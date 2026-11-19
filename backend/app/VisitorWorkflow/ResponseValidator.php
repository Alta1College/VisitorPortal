<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Rules\SafeRasterImage;
use App\VisitorWorkflow\Models\FormField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates a visitor's answers and policy acceptances against a workflow definition.
 * Server-side and authoritative: kiosk and mobile screens use the same rules (one
 * implementation of visitor registration, brief §12).
 */
class ResponseValidator
{
    public function __construct(private readonly ConditionEvaluator $conditions) {}

    /**
     * @param  array<string, mixed>  $answers  keyed by field key
     * @param  list<int>  $acceptedPolicyVersionIds
     * @param  bool  $checkPolicies  false to validate answers only (a screen's questions step)
     * @return ValidatedWorkflow answers of visible fields only, normalised
     *
     * @throws ValidationException errors keyed answers.<field key> and policies.<policy id>
     */
    public function validate(WorkflowDefinition $definition, array $answers, array $acceptedPolicyVersionIds, bool $checkPolicies = true): ValidatedWorkflow
    {
        $visible = $this->conditions->visibleKeys($definition, $answers);
        $rules = [];
        $attributes = [];

        foreach ($definition->fields as $resolved) {
            $key = $resolved->field->key;

            if (! in_array($key, $visible, true)) {
                continue;
            }

            $rules['answers.'.$key] = $this->rulesFor($resolved);
            $attributes['answers.'.$key] = $resolved->field->label;
        }

        $accepted = array_map('intval', $acceptedPolicyVersionIds);
        $policyErrors = [];

        foreach ($checkPolicies ? $definition->policies : [] as $version) {
            if (! in_array($version->getKey(), $accepted, true)) {
                $policyErrors['policies.'.$version->policy_id] = __(':policy muss akzeptiert werden.', ['policy' => $version->title]);
            }
        }

        $validator = Validator::make(['answers' => array_intersect_key($answers, array_flip($visible))], $rules, [], $attributes);
        $validator->after(function ($validator) use ($policyErrors): void {
            foreach ($policyErrors as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });

        $validated = $validator->validate()['answers'] ?? [];

        $normalised = [];

        foreach ($visible as $key) {
            $value = $validated[$key] ?? null;

            if ($value !== null && $value !== '') {
                $normalised[$key] = $this->normalise($definition->field($key)->field, $value);
            }
        }

        return new ValidatedWorkflow($definition, $normalised);
    }

    /**
     * @return list<mixed>
     */
    private function rulesFor(ResolvedField $resolved): array
    {
        $field = $resolved->field;
        $v = $field->validation ?? [];
        $presence = $resolved->required ? 'required' : 'nullable';

        return match ($field->type) {
            FormField::TYPE_SHORT_TEXT => [$presence, 'string', 'max:'.min((int) ($v['max_length'] ?? 255), 255)],
            FormField::TYPE_LONG_TEXT => [$presence, 'string', 'max:'.min((int) ($v['max_length'] ?? 2000), 5000)],
            FormField::TYPE_EMAIL => [$presence, 'string', 'email:rfc', 'max:191'],
            FormField::TYPE_PHONE => [$presence, 'string', 'max:40', 'regex:/^[0-9+()\s.\-\/]{3,40}$/'],
            FormField::TYPE_NUMBER => array_values(array_filter([
                $presence, 'numeric',
                isset($v['min']) ? 'min:'.$v['min'] : null,
                isset($v['max']) ? 'max:'.$v['max'] : null,
            ])),
            FormField::TYPE_DATE => array_values(array_filter([
                $presence, 'date_format:Y-m-d',
                isset($v['min']) ? 'after_or_equal:'.$v['min'] : null,
                isset($v['max']) ? 'before_or_equal:'.$v['max'] : null,
            ])),
            FormField::TYPE_YES_NO => [$resolved->required ? 'required' : 'nullable', 'boolean'],
            FormField::TYPE_CHECKBOX, FormField::TYPE_ACKNOWLEDGEMENT => $resolved->required ? ['accepted'] : ['nullable', 'boolean'],
            FormField::TYPE_DROPDOWN => [$presence, 'string', Rule::in($field->optionValues())],
            FormField::TYPE_PHOTO, FormField::TYPE_SIGNATURE => [$presence, 'file', 'max:'.(int) ($v['max_kb'] ?? 5120), new SafeRasterImage],
            FormField::TYPE_FILE => [$presence, 'file', 'max:'.(int) ($v['max_kb'] ?? 5120), 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
            default => ['prohibited'],
        };
    }

    private function normalise(FormField $field, mixed $value): mixed
    {
        return match ($field->type) {
            FormField::TYPE_YES_NO, FormField::TYPE_CHECKBOX, FormField::TYPE_ACKNOWLEDGEMENT => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            FormField::TYPE_PHOTO, FormField::TYPE_SIGNATURE, FormField::TYPE_FILE => $value,
            default => trim((string) $value),
        };
    }
}
