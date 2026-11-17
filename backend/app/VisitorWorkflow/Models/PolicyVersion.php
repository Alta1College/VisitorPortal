<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use LogicException;

/**
 * One published wording of a policy. Never changed after publishing, so an acceptance always
 * points at exactly the text the visitor saw. content_hash lets an auditor check that.
 *
 * @property int $policy_id
 * @property int $version
 * @property string $title
 * @property string $body
 * @property string $acceptance_label
 * @property string $content_hash
 * @property Carbon $published_at
 */
class PolicyVersion extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'policy_id',
        'version',
        'title',
        'body',
        'acceptance_label',
        'content_hash',
        'published_by_user_id',
        'published_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Published policy versions cannot be changed; publish a new version.'));
        static::deleting(fn (): never => throw new LogicException('Published policy versions cannot be deleted.'));
    }

    public static function hashContent(string $title, string $body, string $acceptanceLabel): string
    {
        return hash('sha256', json_encode([$title, $body, $acceptanceLabel], JSON_UNESCAPED_UNICODE));
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /**
     * Body rendered from Markdown with raw HTML stripped and unsafe links disabled.
     */
    public function renderedBody(): HtmlString
    {
        return new HtmlString(Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
