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
 * Where a policy applies. Both null = organisation-wide; a site and/or visitor type narrows it.
 *
 * @property int $policy_id
 * @property int|null $visitor_type_id
 * @property int|null $site_id
 */
class PolicyAssignment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'policy_id',
        'visitor_type_id',
        'site_id',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
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
