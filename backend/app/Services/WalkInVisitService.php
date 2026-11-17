<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\VisitStatusEnum;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;

/**
 * Creates the visit record for an unannounced arrival. Extracted unchanged from
 * CheckInOutBoard so reception and the public visitor channels create walk-ins the same way.
 * Visitor matching is deliberately not here: staff and public channels match differently.
 */
class WalkInVisitService
{
    /**
     * @param  User|null  $createdBy  null for a visitor registering themselves
     */
    public function createVisit(Visitor $visitor, ?User $createdBy, int $hostId, int $siteId, bool $isConfidential, ?string $notes = null): Visit
    {
        $displayName = trim(implode(' ', array_filter([
            $visitor->title,
            $visitor->first_name,
            $visitor->name,
        ])));

        return Visit::query()->create([
            'title' => __('Walk-in: :name', ['name' => $displayName !== '' ? $displayName : __('Gast')]),
            'site_id' => $siteId,
            'host_user_id' => $hostId,
            'created_by_user_id' => $createdBy?->id,
            'scheduled_from' => now(),
            'scheduled_until' => now()->addHour(),
            'status' => VisitStatusEnum::Planned->value,
            'is_walk_in' => true,
            'is_confidential' => $isConfidential,
            'notes' => $notes ?? __('Am Empfang als Walk-in erfasst.'),
        ]);
    }
}
