<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One emergency roll call (or drill) at a site: a snapshot of who was signed in when it started.
 *
 * @property int $site_id
 * @property string $kind
 * @property Carbon $started_at
 * @property int|null $started_by_user_id
 * @property Carbon|null $ended_at
 * @property int|null $ended_by_user_id
 */
class RollCall extends Model
{
    public const KIND_EMERGENCY = 'emergency';

    public const KIND_DRILL = 'drill';

    /**
     * @var list<string>
     */
    protected $fillable = ['site_id', 'kind', 'started_at', 'started_by_user_id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RollCallEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_EMERGENCY => __('Notfall'),
            self::KIND_DRILL => __('Übung'),
        ];
    }

    /**
     * @return array<string, int> status => count
     */
    public function counts(): array
    {
        $counts = $this->entries()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return array_map(fn (string $status): int => (int) ($counts[$status] ?? 0), array_combine(RollCallEntry::STATUSES, RollCallEntry::STATUSES));
    }
}
