<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

/**
 * One administrator-editable setting. The key is the config() path the value is overlaid onto,
 * so upstream call sites such as config('branding.name') pick up stored values unchanged.
 */
final class SettingDefinition
{
    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_EMAIL = 'email';

    public const TYPE_COLOR = 'color';

    public const TYPE_IMAGE = 'image';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_LOCALE = 'locale';

    /** An active local user, stored by id. */
    public const TYPE_USER = 'user';

    /** Yes/no stored as '1'/'0'; empty inherits (a toggle could not express "inherit"). */
    public const TYPE_YES_NO = 'yes_no';

    /**
     * @param  list<mixed>  $rules  Validation rules applied to non-null values.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $group,
        public readonly array $rules = [],
        public readonly bool $siteOverridable = false,
        public readonly bool $required = false,
    ) {}

    /**
     * Form field name: dots are not allowed in Livewire state keys.
     */
    public function field(): string
    {
        return str_replace('.', '__', $this->key);
    }

    public function cast(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            self::TYPE_INTEGER, self::TYPE_USER => (int) $value,
            self::TYPE_COLOR => strtolower(trim((string) $value)),
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
