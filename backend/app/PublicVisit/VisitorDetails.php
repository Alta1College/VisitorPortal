<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Support\VisitorContactRequirement;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The personal details a visitor enters on any public channel, validated the same way
 * everywhere (the screens validate step by step; RegisterArrival validates again on submit).
 *
 * Each visitor type decides which details it asks for: every field is hidden, optional or
 * required, with an optional label, and email/phone can be "at least one". First and last
 * name are always required (a visit needs a name). Types without their own settings use the
 * organisation default (upstream's visitor contact requirement).
 */
final class VisitorDetails
{
    public const FIELDS = ['first_name', 'name', 'email', 'phone', 'company'];

    /** Fields a visitor type may hide or make optional. */
    public const CONFIGURABLE = ['email', 'phone', 'company'];

    public const HIDDEN = 'hidden';

    public const OPTIONAL = 'optional';

    public const REQUIRED = 'required';

    private const BASE_RULES = [
        'first_name' => ['string', 'max:100'],
        'name' => ['string', 'max:100'],
        'email' => ['string', 'email:rfc', 'max:191'],
        'phone' => ['string', 'max:40', 'regex:/^[0-9+()\s.\-\/]{3,40}$/'],
        'company' => ['string', 'max:150'],
    ];

    /**
     * @return array<string, string>
     */
    public static function modes(): array
    {
        return [
            self::REQUIRED => __('Pflichtfeld'),
            self::OPTIONAL => __('Optional'),
            self::HIDDEN => __('Nicht abfragen'),
        ];
    }

    /**
     * Effective settings for a type (or the organisation default).
     *
     * @return array{fields: array<string, array{mode: string, label: ?string}>, email_or_phone: bool}
     */
    public static function settings(?VisitorType $type = null): array
    {
        $requirement = VisitorContactRequirement::current();
        $fields = [
            'first_name' => ['mode' => self::REQUIRED, 'label' => null],
            'name' => ['mode' => self::REQUIRED, 'label' => null],
            'email' => ['mode' => VisitorContactRequirement::requiresEmail($requirement) ? self::REQUIRED : self::OPTIONAL, 'label' => null],
            'phone' => ['mode' => VisitorContactRequirement::requiresPhone($requirement) ? self::REQUIRED : self::OPTIONAL, 'label' => null],
            'company' => ['mode' => self::OPTIONAL, 'label' => null],
        ];
        $emailOrPhone = VisitorContactRequirement::requiresOne($requirement);

        $stored = $type?->detail_fields;

        if (is_array($stored)) {
            foreach (self::FIELDS as $field) {
                $mode = $stored[$field]['mode'] ?? null;

                if (in_array($field, self::CONFIGURABLE, true) && in_array($mode, [self::HIDDEN, self::OPTIONAL, self::REQUIRED], true)) {
                    $fields[$field]['mode'] = $mode;
                }

                $label = trim((string) ($stored[$field]['label'] ?? ''));
                $fields[$field]['label'] = $label !== '' ? mb_substr($label, 0, 80) : null;
            }

            $emailOrPhone = (bool) ($stored['email_or_phone'] ?? false);
        }

        // "At least one of email or phone" only applies while both are asked for.
        $emailOrPhone = $emailOrPhone && $fields['email']['mode'] !== self::HIDDEN && $fields['phone']['mode'] !== self::HIDDEN;

        return ['fields' => $fields, 'email_or_phone' => $emailOrPhone];
    }

    /**
     * Fields shown for a type, in display order.
     *
     * @return list<string>
     */
    public static function visible(?VisitorType $type = null): array
    {
        return array_keys(self::rules($type));
    }

    /**
     * Whether each visible field must be filled in (for the asterisk on screens).
     *
     * @return array<string, bool>
     */
    public static function required(?VisitorType $type = null): array
    {
        return array_map(fn (array $rules): bool => in_array('required', $rules, true), self::rules($type));
    }

    /**
     * Rules for the visible fields only, in display order.
     *
     * @return array<string, list<string>>
     */
    public static function rules(?VisitorType $type = null): array
    {
        $settings = self::settings($type);
        $rules = [];

        foreach ($settings['fields'] as $field => $config) {
            if ($config['mode'] === self::HIDDEN) {
                continue;
            }

            $rules[$field] = [$config['mode'] === self::REQUIRED ? 'required' : 'nullable', ...self::BASE_RULES[$field]];
        }

        if ($settings['email_or_phone']) {
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
    public static function validate(array $details, ?VisitorType $type = null): array
    {
        $rules = self::rules($type);
        // Hidden fields are dropped: a visitor cannot submit a detail the type does not ask for.
        $input = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, array_intersect_key($details, $rules));

        $validated = Validator::make($input, $rules, [], self::labels($type))->validate();

        return [
            'first_name' => $validated['first_name'],
            'name' => $validated['name'],
            'email' => isset($validated['email']) ? mb_strtolower($validated['email']) : null,
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
        ];
    }

    /**
     * Built-in labels (translated).
     *
     * @return array<string, string>
     */
    public static function defaultLabels(): array
    {
        return [
            'first_name' => __('Vorname'),
            'name' => __('Nachname'),
            'email' => __('E-Mail'),
            'phone' => __('Telefon'),
            'company' => __('Firma'),
        ];
    }

    /**
     * Labels for a type: its own wording where set, otherwise the built-in label.
     *
     * @return array<string, string>
     */
    public static function labels(?VisitorType $type = null): array
    {
        $fields = self::settings($type)['fields'];
        $labels = [];

        foreach (self::defaultLabels() as $field => $default) {
            $labels[$field] = $fields[$field]['label'] ?? $default;
        }

        return $labels;
    }
}
