<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Models\Site;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitActionService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Finds visitors currently signed in at a site, for self sign-out at a kiosk. Never returns
 * ids to the screen: each match is an encrypted option token bound to the site with a short
 * expiry (like host options), and only a first name and last initial are shown.
 *
 * Unattended kiosks need the exact first and last name; staffed kiosks may suggest names as
 * the visitor types (reception can see who is on site anyway).
 */
class ActiveAttendances
{
    public const OPTION_TTL_SECONDS = 600;

    public const MAX_RESULTS = 5;

    public function __construct(private readonly VisitActionService $visitActions) {}

    /**
     * Exact (case-insensitive) first and last name.
     *
     * @return list<array{token: string, name: string, since: string}>
     */
    public function exact(Site $site, string $firstName, string $lastName): array
    {
        $first = mb_strtolower(trim($firstName));
        $last = mb_strtolower(trim($lastName));

        if ($first === '' || $last === '') {
            return [];
        }

        return $this->options($site, $this->activeQuery($site)
            ->whereRaw('LOWER(visitors.first_name) = ?', [$first])
            ->whereRaw('LOWER(visitors.name) = ?', [$last]));
    }

    /**
     * Prefix of first or last name (staffed kiosks only), at least two characters.
     *
     * @return list<array{token: string, name: string, since: string}>
     */
    public function suggest(Site $site, string $query): array
    {
        $query = mb_strtolower(trim($query));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $like = addcslashes($query, '\\%_').'%';
        $parts = preg_split('/\s+/', $query) ?: [];

        $builder = $this->activeQuery($site)->where(function ($q) use ($like, $parts): void {
            $q->whereRaw('LOWER(visitors.first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(visitors.name) LIKE ?', [$like]);

            // "pat pa" matches first name "Pat" and last name starting "Pa".
            if (count($parts) >= 2) {
                $q->orWhere(fn ($both) => $both
                    ->whereRaw('LOWER(visitors.first_name) = ?', [$parts[0]])
                    ->whereRaw('LOWER(visitors.name) LIKE ?', [addcslashes($parts[1], '\\%_').'%']));
            }
        });

        return $this->options($site, $builder);
    }

    /**
     * The attendance behind an option token, if it is still for this site and still active.
     *
     * @return array{0: Visit, 1: Visitor}|null
     */
    public function resolve(Site $site, string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['s'] ?? null) !== $site->getKey() || ($payload['e'] ?? 0) < now()->timestamp) {
            return null;
        }

        $visit = Visit::query()->find($payload['v'] ?? 0);
        $visitor = Visitor::query()->find($payload['p'] ?? 0);

        return $visit !== null && $visitor !== null && $this->isActive($visit, $visitor) && $visit->site_id === $site->getKey()
            ? [$visit, $visitor]
            : null;
    }

    public function isActive(Visit $visit, Visitor $visitor): bool
    {
        return $this->visitActions->canOperate($visit)
            && DB::table('visit_visitor')
                ->where('visit_id', $visit->getKey())
                ->where('visitor_id', $visitor->getKey())
                ->whereNotNull('checked_in_at')
                ->whereNull('checked_out_at')
                ->exists();
    }

    /**
     * Active attendances of one visitor at a site (to close a forgotten one on return).
     *
     * @return list<int> visit ids
     */
    public function activeVisitIdsFor(Site $site, Visitor $visitor): array
    {
        return $this->activeQuery($site)->where('visitors.id', $visitor->getKey())->pluck('visits.id')->map(fn ($id) => (int) $id)->all();
    }

    private function activeQuery(Site $site)
    {
        return DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->join('visitors', 'visitors.id', '=', 'visit_visitor.visitor_id')
            ->where('visits.site_id', $site->getKey())
            ->where('visits.status', 'planned')
            ->whereNotNull('visit_visitor.checked_in_at')
            ->whereNull('visit_visitor.checked_out_at');
    }

    /**
     * @return list<array{token: string, name: string, since: string}>
     */
    private function options(Site $site, $builder): array
    {
        $rows = $builder
            ->orderByDesc('visit_visitor.checked_in_at')
            ->limit(self::MAX_RESULTS)
            ->get(['visits.id as visit_id', 'visitors.id as visitor_id', 'visitors.first_name', 'visitors.name', 'visit_visitor.checked_in_at']);

        return $rows->map(fn ($row): array => [
            'token' => Crypt::encryptString(json_encode([
                'v' => (int) $row->visit_id,
                'p' => (int) $row->visitor_id,
                's' => $site->getKey(),
                'e' => now()->addSeconds(self::OPTION_TTL_SECONDS)->timestamp,
            ])),
            'name' => trim($row->first_name.' '.mb_substr((string) $row->name, 0, 1).'.'),
            'since' => Carbon::parse($row->checked_in_at)->timezone($site->timezone ?: config('app.timezone'))->format('H:i'),
        ])->values()->all();
    }
}
