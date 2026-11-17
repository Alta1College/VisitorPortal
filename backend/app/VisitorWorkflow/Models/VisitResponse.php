<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Models;

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * One answer given by one visitor on one visit. Field key, label and type are snapshotted so
 * later changes to the field definition do not rewrite history.
 *
 * @property int $visit_id
 * @property int $visitor_id
 * @property int|null $form_field_id
 * @property string $field_key
 * @property string $field_label
 * @property string $field_type
 * @property string|null $value
 * @property string|null $file_path
 */
class VisitResponse extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'visit_id',
        'visitor_id',
        'form_field_id',
        'field_key',
        'field_label',
        'field_type',
        'is_sensitive',
        'value',
        'file_path',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
    ];

    /**
     * The answer as given, decrypting sensitive values.
     */
    public function plainValue(): ?string
    {
        if ($this->value === null || ! $this->is_sensitive) {
            return $this->value;
        }

        return Crypt::decryptString($this->value);
    }

    public static function storableValue(?string $value, bool $sensitive): ?string
    {
        return $value !== null && $sensitive ? Crypt::encryptString($value) : $value;
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }
}
