<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $site_id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by_user_id
 */
class SiteSetting extends Model
{
    protected $table = 'site_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'key',
        'value',
        'updated_by_user_id',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
