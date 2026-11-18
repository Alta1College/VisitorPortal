<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\QrCheckIn;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A site's mobile check-in link (/visit/{token}).
 *
 * @property int $id
 * @property int $site_id
 * @property string $token
 * @property bool $is_enabled
 * @property Carbon|null $rotated_at
 * @property-read Site $site
 */
class SiteQrLink extends Model
{
    protected $fillable = ['site_id', 'token', 'is_enabled', 'rotated_at'];

    protected $hidden = ['token'];

    protected $attributes = ['is_enabled' => true];

    protected $casts = [
        'is_enabled' => 'boolean',
        'rotated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
