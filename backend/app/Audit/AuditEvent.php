<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Audit;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only audit record. Rows are never updated or deleted through the model; only the
 * retention purge removes expired rows, in bulk, and records that it did so.
 *
 * @property Carbon $occurred_at
 * @property string $action
 * @property string $result
 * @property string $channel
 * @property int|null $actor_user_id
 * @property int|null $site_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $ip_hash
 * @property array<string, mixed>|null $context
 */
class AuditEvent extends Model
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const RESULT_DENIED = 'denied';

    public $timestamps = false;

    protected $table = 'audit_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'occurred_at',
        'action',
        'result',
        'channel',
        'actor_user_id',
        'site_id',
        'subject_type',
        'subject_id',
        'ip_hash',
        'context',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'context' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit events are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
