<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a field is asked. Null visitor type / site = all types / all sites.
 *
 * @property int $form_field_id
 * @property int|null $visitor_type_id
 * @property int|null $site_id
 * @property bool $is_required
 * @property int $sort_order
 */
class FormFieldAssignment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'form_field_id',
        'visitor_type_id',
        'site_id',
        'is_required',
        'sort_order',
    ];

    protected $attributes = [
        'is_required' => false,
        'sort_order' => 0,
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    public function visitorType(): BelongsTo
    {
        return $this->belongsTo(VisitorType::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
