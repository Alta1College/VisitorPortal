<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Models\User;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Publishes a new policy wording as the next version. Earlier versions, and every acceptance
 * that points at them, are never touched.
 */
class PolicyPublisher
{
    public function publish(Policy $policy, string $title, string $body, string $acceptanceLabel, ?User $publisher): PolicyVersion
    {
        $title = trim($title);
        $body = trim($body);
        $acceptanceLabel = trim($acceptanceLabel);

        if ($title === '' || $body === '' || $acceptanceLabel === '') {
            throw new InvalidArgumentException('Title, text and acceptance wording are required.');
        }

        $hash = PolicyVersion::hashContent($title, $body, $acceptanceLabel);

        return DB::transaction(function () use ($policy, $title, $body, $acceptanceLabel, $hash, $publisher): PolicyVersion {
            // Lock the policy row so two publishers cannot claim the same version number.
            Policy::query()->whereKey($policy->getKey())->lockForUpdate()->first();

            $current = PolicyVersion::query()->where('policy_id', $policy->getKey())->orderByDesc('version')->first();

            if ($current !== null && $current->content_hash === $hash) {
                throw new InvalidArgumentException('The wording is unchanged from the current version.');
            }

            return PolicyVersion::query()->create([
                'policy_id' => $policy->getKey(),
                'version' => ($current?->version ?? 0) + 1,
                'title' => $title,
                'body' => $body,
                'acceptance_label' => $acceptanceLabel,
                'content_hash' => $hash,
                'published_by_user_id' => $publisher?->getKey(),
                'published_at' => now(),
            ]);
        });
    }
}
