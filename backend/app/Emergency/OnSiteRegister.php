<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Models\Site;
use App\Models\User;
use App\Support\ForkPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everyone currently signed in at a site (brief §21): anyone checked in and not checked out,
 * whatever the state of their visit, so nobody is left off in an emergency. People signed in
 * before today are flagged: they may have forgotten to sign out.
 */
class OnSiteRegister
{
    /**
     * Active sites the user may see the register and run roll calls for.
     *
     * @return Collection<int, Site>
     */
    public function sitesFor(User $user): Collection
    {
        if (! $user->can(ForkPermissions::RUN_ROLL_CALL)) {
            return collect();
        }

        $query = Site::query()->where('is_active', true)->orderBy('name');

        if (! $user->can('ViewAny:Visit')) {
            $query->whereIn('id', $user->assignedSiteIds()->all());
        }

        return $query->get();
    }

    public function canAccess(User $user, int $siteId): bool
    {
        return $this->sitesFor($user)->contains(fn (Site $site): bool => $site->getKey() === $siteId);
    }

    /**
     * @return list<array{visit_id: int, visitor_id: int, first_name: string, last_name: ?string, company: ?string, visitor_type: ?string, host_name: ?string, checked_in_at: Carbon, via: ?string, safety: array<string, string>, earlier_day: bool}>
     */
    public function people(Site $site): array
    {
        $timezone = $site->timezone ?: (string) config('app.timezone');
        $today = now($timezone)->startOfDay();

        $rows = DB::table('visit_visitor')
            ->join('visits', 'visits.id', '=', 'visit_visitor.visit_id')
            ->join('visitors', 'visitors.id', '=', 'visit_visitor.visitor_id')
            ->leftJoin('users as hosts', 'hosts.id', '=', 'visits.host_user_id')
            ->leftJoin('visitor_types', 'visitor_types.id', '=', 'visit_visitor.visitor_type_id')
            ->where('visits.site_id', $site->getKey())
            ->whereNotNull('visit_visitor.checked_in_at')
            ->whereNull('visit_visitor.checked_out_at')
            ->orderBy('visitors.first_name')
            ->orderBy('visitors.name')
            ->get([
                'visit_visitor.visit_id', 'visit_visitor.visitor_id', 'visit_visitor.checked_in_at', 'visit_visitor.checked_in_via',
                'visitors.first_name', 'visitors.name', 'visitors.company',
                'hosts.first_name as host_first_name', 'hosts.name as host_last_name',
                'visitor_types.name as visitor_type',
            ]);

        $safety = $this->safetyNotes($rows->pluck('visit_id')->unique()->all());

        return $rows->map(function (object $row) use ($safety, $timezone, $today): array {
            $checkedIn = Carbon::parse($row->checked_in_at)->timezone($timezone);
            $host = trim(($row->host_first_name ?? '').' '.($row->host_last_name ?? ''));

            return [
                'visit_id' => (int) $row->visit_id,
                'visitor_id' => (int) $row->visitor_id,
                'first_name' => (string) $row->first_name,
                'last_name' => $row->name,
                'company' => $row->company,
                'visitor_type' => $row->visitor_type,
                'host_name' => $host !== '' ? $host : null,
                'checked_in_at' => $checkedIn,
                'via' => $row->checked_in_via,
                'safety' => $safety[$row->visit_id.'|'.$row->visitor_id] ?? [],
                'earlier_day' => $checkedIn->lt($today),
            ];
        })->values()->all();
    }

    /**
     * Answers to questions marked "show on roll call", per attendance.
     *
     * @param  list<int>  $visitIds
     * @return array<string, array<string, string>> "visit|visitor" => label => answer
     */
    private function safetyNotes(array $visitIds): array
    {
        if ($visitIds === []) {
            return [];
        }

        $notes = [];

        VisitResponse::query()
            ->whereIn('visit_id', $visitIds)
            ->whereHas('field', fn ($q) => $q->where('show_on_roll_call', true))
            ->whereNotIn('field_type', FormField::FILE_TYPES)
            ->with('field')
            ->orderBy('id')
            ->get()
            ->each(function (VisitResponse $response) use (&$notes): void {
                $value = $response->plainValue();

                if ($response->field !== null && filled($value)) {
                    $notes[$response->visit_id.'|'.$response->visitor_id][$response->field->label] = $response->field->displayAnswer((string) $value);
                }
            });

        return $notes;
    }
}
