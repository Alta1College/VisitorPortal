<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A policy visitors must accept. The wording lives in immutable PolicyVersion rows; the
 * policy itself only carries its name, active flag and where it applies.
 *
 * @property string $name
 * @property bool $is_active
 */
class Policy extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(PolicyVersion::class)->ofMany('version', 'max');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PolicyAssignment::class);
    }
}
