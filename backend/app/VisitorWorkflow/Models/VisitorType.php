<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A configurable category of visitor (e.g. contractor, parent, delivery). Types are data, not
 * code: what a type requires comes from its field and policy assignments.
 *
 * @property string $name
 * @property string $slug
 * @property string $host_requirement
 * @property bool $is_active
 */
class VisitorType extends Model
{
    public const HOST_REQUIRED = 'required';

    public const HOST_OPTIONAL = 'optional';

    public const HOST_NONE = 'none';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'host_requirement',
        'badge_label',
        'badge_color',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'host_requirement' => self::HOST_REQUIRED,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return array<string, string>
     */
    public static function hostRequirements(): array
    {
        return [
            self::HOST_REQUIRED => __('Gastgeber erforderlich'),
            self::HOST_OPTIONAL => __('Gastgeber optional'),
            self::HOST_NONE => __('Kein Gastgeber'),
        ];
    }

    /**
     * Sites the type is limited to. Empty = available at every site.
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'visitor_type_site');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active types offered at a site, in display order.
     */
    public function scopeAvailableAt(Builder $query, Site $site): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('sites')
                ->orWhereHas('sites', fn (Builder $s) => $s->whereKey($site->getKey())))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function isAvailableAt(Site $site): bool
    {
        return static::query()->availableAt($site)->whereKey($this->getKey())->exists();
    }
}
