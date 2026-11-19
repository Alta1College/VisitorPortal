<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person on a roll call. Names and details are copied at the start, so the entry stays
 * even if the visit is later signed out, changed or purged.
 *
 * @property int $roll_call_id
 * @property int|null $visit_id
 * @property int|null $visitor_id
 * @property string $source
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $company
 * @property string|null $visitor_type
 * @property string|null $host_name
 * @property Carbon|null $checked_in_at
 * @property array<string, string>|null $safety_notes
 * @property string $status
 * @property Carbon|null $status_changed_at
 * @property int|null $status_changed_by_user_id
 */
class RollCallEntry extends Model
{
    public const UNACCOUNTED = 'unaccounted';

    public const ACCOUNTED = 'accounted';

    public const LEFT_SITE = 'left_site';

    public const STATUSES = [self::UNACCOUNTED, self::ACCOUNTED, self::LEFT_SITE];

    public const SOURCE_SNAPSHOT = 'snapshot';

    public const SOURCE_LATE = 'late';

    public const SOURCE_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'roll_call_id', 'visit_id', 'visitor_id', 'source', 'first_name', 'last_name', 'company',
        'visitor_type', 'host_name', 'checked_in_at', 'safety_notes', 'status',
    ];

    protected $attributes = [
        'status' => self::UNACCOUNTED,
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'safety_notes' => 'encrypted:array',
    ];

    public function rollCall(): BelongsTo
    {
        return $this->belongsTo(RollCall::class);
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::UNACCOUNTED => __('Nicht erfasst'),
            self::ACCOUNTED => __('Anwesend bestätigt'),
            self::LEFT_SITE => __('Gelände verlassen'),
        ];
    }
}
