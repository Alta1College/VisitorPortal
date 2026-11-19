<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question or data field a visitor may be asked. Requirements such as a working-with-children
 * card number and expiry are configured as fields; there is no field-specific business logic.
 *
 * @property string $key
 * @property string $label
 * @property string $type
 * @property array<int, array{value: string, label: string}>|null $options
 * @property array<string, mixed>|null $validation
 * @property array{match?: string, rules?: list<array{field: string, operator: string, value?: mixed}>}|null $conditions
 * @property bool $is_sensitive
 * @property bool $remember_for_returning
 * @property bool $is_active
 */
class FormField extends Model
{
    public const TYPE_SHORT_TEXT = 'short_text';

    public const TYPE_LONG_TEXT = 'long_text';

    public const TYPE_EMAIL = 'email';

    public const TYPE_PHONE = 'phone';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_YES_NO = 'yes_no';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_DROPDOWN = 'dropdown';

    public const TYPE_ACKNOWLEDGEMENT = 'acknowledgement';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_SIGNATURE = 'signature';

    public const TYPE_FILE = 'file';

    public const FILE_TYPES = [self::TYPE_PHOTO, self::TYPE_SIGNATURE, self::TYPE_FILE];

    /** Types whose answer may be pre-filled for returning visitors. Consent and signatures are always given afresh. */
    public const REMEMBERABLE_TYPES = [
        self::TYPE_SHORT_TEXT, self::TYPE_LONG_TEXT, self::TYPE_EMAIL, self::TYPE_PHONE, self::TYPE_NUMBER,
        self::TYPE_DATE, self::TYPE_YES_NO, self::TYPE_DROPDOWN, self::TYPE_PHOTO, self::TYPE_FILE,
    ];

    public function remembersAnswer(): bool
    {
        return $this->remember_for_returning && in_array($this->type, self::REMEMBERABLE_TYPES, true);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'help_text',
        'type',
        'options',
        'validation',
        'conditions',
        'is_sensitive',
        'show_on_badge',
        'show_on_roll_call',
        'remember_for_returning',
        'is_active',
    ];

    protected $attributes = [
        'is_sensitive' => false,
        'show_on_badge' => false,
        'show_on_roll_call' => false,
        'remember_for_returning' => false,
        'is_active' => true,
    ];

    protected $casts = [
        'options' => 'array',
        'validation' => 'array',
        'conditions' => 'array',
        'is_sensitive' => 'boolean',
        'show_on_badge' => 'boolean',
        'show_on_roll_call' => 'boolean',
        'remember_for_returning' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_SHORT_TEXT => __('Kurztext'),
            self::TYPE_LONG_TEXT => __('Langtext'),
            self::TYPE_EMAIL => __('E-Mail'),
            self::TYPE_PHONE => __('Telefon'),
            self::TYPE_NUMBER => __('Zahl'),
            self::TYPE_DATE => __('Datum'),
            self::TYPE_YES_NO => __('Ja/Nein'),
            self::TYPE_CHECKBOX => __('Kontrollkästchen'),
            self::TYPE_DROPDOWN => __('Auswahlliste'),
            self::TYPE_ACKNOWLEDGEMENT => __('Bestätigung'),
            self::TYPE_PHOTO => __('Foto'),
            self::TYPE_SIGNATURE => __('Unterschrift'),
            self::TYPE_FILE => __('Datei/Nachweis'),
        ];
    }

    public function isFile(): bool
    {
        return in_array($this->type, self::FILE_TYPES, true);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FormFieldAssignment::class);
    }

    /**
     * @return list<string>
     */
    public function optionValues(): array
    {
        return array_values(array_map(fn (array $option): string => (string) $option['value'], $this->options ?? []));
    }

    /**
     * A stored answer as people read it (Yes/No, the option's label).
     */
    public function displayAnswer(string $value): string
    {
        return match ($this->type) {
            self::TYPE_YES_NO, self::TYPE_CHECKBOX, self::TYPE_ACKNOWLEDGEMENT => $value === '1' ? __('Ja') : __('Nein'),
            self::TYPE_DROPDOWN => (string) (collect($this->options ?? [])->firstWhere('value', $value)['label'] ?? $value),
            default => $value,
        };
    }
}
