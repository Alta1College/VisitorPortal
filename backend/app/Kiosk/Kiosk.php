<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sign-in device registered to one site. It never authenticates as a user: it holds a random
 * device secret (cookie) whose hash identifies it here. Revoking it ends access immediately.
 *
 * @property int $site_id
 * @property string $name
 * @property Carbon|null $enrolment_code_expires_at
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property bool $is_staffed
 * @property int $idle_timeout_seconds
 */
class Kiosk extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'name',
        'is_staffed',
        'idle_timeout_seconds',
    ];

    protected $hidden = [
        'enrolment_code_hash',
        'device_token_hash',
    ];

    protected $attributes = [
        'is_staffed' => false,
        'idle_timeout_seconds' => 90,
    ];

    protected $casts = [
        'enrolment_code_expires_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_staffed' => 'boolean',
        'idle_timeout_seconds' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->whereHas('site', fn (Builder $q) => $q->where('is_active', true));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasPendingEnrolment(): bool
    {
        return $this->enrolment_code_expires_at !== null && $this->enrolment_code_expires_at->isFuture();
    }

    /**
     * Status label for administrators.
     */
    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->enrolled_at !== null => 'enrolled',
            $this->hasPendingEnrolment() => 'awaiting_enrolment',
            default => 'not_enrolled',
        };
    }
}
