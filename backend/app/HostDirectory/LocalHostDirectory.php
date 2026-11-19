<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory;

use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

/**
 * Hosts are the site's active local users (home site or assigned site), excluding welcome-monitor
 * accounts: the same population reception can choose from. No cloud dependency.
 */
class LocalHostDirectory implements HostDirectory
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_RESULTS = 5;

    public const OPTION_TTL_MINUTES = 30;

    public function search(Site $site, string $query): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $prefix = addcslashes($query, '%_\\').'%';

        return $this->hostsAt($site)
            ->where(fn (Builder $q) => $q
                ->where('first_name', 'like', $prefix)
                ->orWhere('name', 'like', $prefix)
                ->orWhereRaw("CONCAT(first_name, ' ', name) LIKE ?", [$prefix]))
            ->orderBy('first_name')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'first_name', 'name'])
            ->map(fn (User $user): HostOption => new HostOption(
                $this->tokenFor($site, $user),
                trim($user->first_name.' '.$user->name),
            ))
            ->all();
    }

    public function resolve(Site $site, string $optionToken): ?User
    {
        try {
            $payload = json_decode(Crypt::decryptString($optionToken), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['s'] ?? null) !== $site->getKey()
            || ! is_int($payload['e'] ?? null)
            || $payload['e'] < now()->getTimestamp()) {
            return null;
        }

        // Re-check availability: the host may have been deactivated since the search.
        return $this->hostsAt($site)->whereKey($payload['u'] ?? 0)->first();
    }

    private function tokenFor(Site $site, User $user): string
    {
        return Crypt::encryptString(json_encode([
            'u' => $user->getKey(),
            's' => $site->getKey(),
            'e' => now()->addMinutes(self::OPTION_TTL_MINUTES)->getTimestamp(),
        ]));
    }

    private function hostsAt(Site $site): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', ['welcome monitor', 'welcome_monitor']))
            ->where(fn (Builder $q) => $q
                ->where('site_id', $site->getKey())
                ->orWhereHas('sites', fn (Builder $s) => $s->whereKey($site->getKey())));
    }
}
