<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Support\VisitorContactRequirement;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The personal details a visitor enters on any public channel, validated the same way
 * everywhere (the screens validate step by step; RegisterArrival validates again on submit).
 */
final class VisitorDetails
{
    public const FIELDS = ['first_name', 'name', 'email', 'phone', 'company'];

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-\/]{3,40}$/'],
            'company' => ['nullable', 'string', 'max:150'],
        ];

        $requirement = VisitorContactRequirement::current();

        if (VisitorContactRequirement::requiresEmail($requirement)) {
            $rules['email'][0] = 'required';
        }

        if (VisitorContactRequirement::requiresPhone($requirement)) {
            $rules['phone'][0] = 'required';
        }

        if (VisitorContactRequirement::requiresOne($requirement)) {
            $rules['email'][] = 'required_without:phone';
            $rules['phone'][] = 'required_without:email';
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{first_name: string, name: string, email: ?string, phone: ?string, company: ?string}
     *
     * @throws ValidationException keyed by field name
     */
    public static function validate(array $details): array
    {
        $rules = self::rules();
        $input = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, array_intersect_key($details, $rules));

        $validated = Validator::make($input, $rules, [], self::labels())->validate();

        return [
            'first_name' => $validated['first_name'],
            'name' => $validated['name'],
            'email' => isset($validated['email']) ? mb_strtolower($validated['email']) : null,
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'first_name' => __('Vorname'),
            'name' => __('Nachname'),
            'email' => __('E-Mail'),
            'phone' => __('Telefon'),
            'company' => __('Firma'),
        ];
    }
}
