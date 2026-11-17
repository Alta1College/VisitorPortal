<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by_user_id
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'updated_by_user_id',
    ];

    protected $casts = [
        'value' => 'json',
    ];
}
