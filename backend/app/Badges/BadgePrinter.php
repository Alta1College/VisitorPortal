<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use App\Badges\Drivers\BrotherQlRaster;
use App\Badges\Drivers\LabelDriver;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A network label printer the server prints badges to directly (e.g. over a site-to-site VPN),
 * so kiosks and phones never see a print dialog.
 *
 * @property int $site_id
 * @property string $name
 * @property string $host
 * @property int $port
 * @property string $driver
 * @property string $label
 * @property bool $is_enabled
 * @property bool $is_site_default
 * @property Carbon|null $last_printed_at
 * @property Carbon|null $last_failed_at
 * @property string|null $last_error
 */
class BadgePrinter extends Model
{
    /** @var array<string, class-string<LabelDriver>> */
    public const DRIVERS = [
        'brother_ql' => BrotherQlRaster::class,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'name',
        'host',
        'port',
        'driver',
        'label',
        'is_enabled',
        'is_site_default',
    ];

    protected $attributes = [
        'port' => 9100,
        'driver' => 'brother_ql',
        'label' => '62x100',
        'is_enabled' => true,
        'is_site_default' => false,
    ];

    protected $casts = [
        'port' => 'integer',
        'is_enabled' => 'boolean',
        'is_site_default' => 'boolean',
        'last_printed_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // One default printer per site.
        static::saved(function (self $printer): void {
            if ($printer->is_site_default) {
                static::query()->where('site_id', $printer->site_id)->whereKeyNot($printer->getKey())
                    ->where('is_site_default', true)->update(['is_site_default' => false]);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function format(): ?LabelFormat
    {
        return LabelFormat::find($this->label);
    }

    public function driverInstance(): ?LabelDriver
    {
        $class = self::DRIVERS[$this->driver] ?? null;

        return $class !== null ? new $class : null;
    }

    /**
     * @return array<string, string>
     */
    public static function driverOptions(): array
    {
        return array_map(fn (string $class): string => $class::label(), self::DRIVERS);
    }

    public function status(): string
    {
        return match (true) {
            ! $this->is_enabled => 'disabled',
            $this->last_failed_at !== null && ($this->last_printed_at === null || $this->last_failed_at->gt($this->last_printed_at)) => 'failing',
            $this->last_printed_at !== null => 'ok',
            default => 'untested',
        };
    }
}
