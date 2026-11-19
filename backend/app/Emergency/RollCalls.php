<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency;

use App\Audit\AuditLogger;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Starting, running and ending roll calls. Every step is audited with who did it.
 */
class RollCalls
{
    public function __construct(
        private readonly OnSiteRegister $register,
        private readonly AuditLogger $audit,
    ) {}

    public function openFor(int $siteId): ?RollCall
    {
        return RollCall::query()->where('site_id', $siteId)->whereNull('ended_at')->latest('started_at')->first();
    }

    /**
     * Snapshot everyone on site now. If a roll call is already open at the site, that one is
     * returned instead (two wardens pressing at once must not split the list).
     */
    public function start(Site $site, User $user, string $kind): RollCall
    {
        if (! array_key_exists($kind, RollCall::kinds())) {
            throw new InvalidArgumentException('Unknown roll call kind.');
        }

        return DB::transaction(function () use ($site, $user, $kind): RollCall {
            // Serialise starts per site.
            Site::query()->whereKey($site->getKey())->lockForUpdate()->first();

            if ($open = $this->openFor($site->getKey())) {
                return $open;
            }

            $rollCall = RollCall::query()->create([
                'site_id' => $site->getKey(),
                'kind' => $kind,
                'started_at' => now(),
                'started_by_user_id' => $user->getKey(),
            ]);

            $people = $this->register->people($site);

            foreach ($people as $person) {
                $this->addPerson($rollCall, $person, RollCallEntry::SOURCE_SNAPSHOT);
            }

            $this->audit->record('roll_call.started', subject: $rollCall, site: $site, context: ['kind' => $kind, 'people' => count($people)], actorUserId: $user->getKey());

            return $rollCall;
        });
    }

    /**
     * People signed in at the site since the roll call started (not on the list yet).
     *
     * @return list<array<string, mixed>>
     */
    public function lateArrivals(RollCall $rollCall): array
    {
        $listed = $rollCall->entries()->whereNotNull('visit_id')->get(['visit_id', 'visitor_id'])
            ->map(fn (RollCallEntry $e): string => $e->visit_id.'|'.$e->visitor_id)->all();

        return array_values(array_filter(
            $this->register->people($rollCall->site),
            fn (array $person): bool => ! in_array($person['visit_id'].'|'.$person['visitor_id'], $listed, true),
        ));
    }

    public function addLate(RollCall $rollCall, int $visitId, int $visitorId, User $user): ?RollCallEntry
    {
        $this->assertOpen($rollCall);
        $person = collect($this->lateArrivals($rollCall))->first(fn (array $p): bool => $p['visit_id'] === $visitId && $p['visitor_id'] === $visitorId);

        if ($person === null) {
            return null;
        }

        $entry = $this->addPerson($rollCall, $person, RollCallEntry::SOURCE_LATE);
        $this->audit->record('roll_call.person_added', subject: $rollCall, site: $rollCall->site_id, context: ['entry_id' => $entry->getKey(), 'source' => 'late', 'visitor_id' => $visitorId], actorUserId: $user->getKey());

        return $entry;
    }

    /**
     * Someone found on site who never signed in.
     */
    public function addManual(RollCall $rollCall, string $name, ?string $note, User $user): RollCallEntry
    {
        $this->assertOpen($rollCall);
        $name = trim($name);

        $entry = $rollCall->entries()->create([
            'source' => RollCallEntry::SOURCE_MANUAL,
            'first_name' => mb_substr($name, 0, 191),
            'company' => filled($note) ? mb_substr(trim((string) $note), 0, 191) : null,
            'status' => RollCallEntry::ACCOUNTED,
        ]);
        $entry->forceFill(['status_changed_at' => now(), 'status_changed_by_user_id' => $user->getKey()])->save();

        $this->audit->record('roll_call.person_added', subject: $rollCall, site: $rollCall->site_id, context: ['entry_id' => $entry->getKey(), 'source' => 'manual'], actorUserId: $user->getKey());

        return $entry;
    }

    public function mark(RollCallEntry $entry, string $status, User $user): void
    {
        $rollCall = $entry->rollCall;
        $this->assertOpen($rollCall);

        if (! in_array($status, RollCallEntry::STATUSES, true) || $entry->status === $status) {
            return;
        }

        $from = $entry->status;
        $entry->forceFill(['status' => $status, 'status_changed_at' => now(), 'status_changed_by_user_id' => $user->getKey()])->save();

        $this->audit->record('roll_call.marked', subject: $rollCall, site: $rollCall->site_id, context: [
            'entry_id' => $entry->getKey(),
            'visitor_id' => $entry->visitor_id,
            'from' => $from,
            'to' => $status,
        ], actorUserId: $user->getKey());
    }

    public function end(RollCall $rollCall, User $user): void
    {
        if (! $rollCall->isOpen()) {
            return;
        }

        $rollCall->forceFill(['ended_at' => now(), 'ended_by_user_id' => $user->getKey()])->save();
        $this->audit->record('roll_call.ended', subject: $rollCall, site: $rollCall->site_id, context: $rollCall->counts(), actorUserId: $user->getKey());
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function addPerson(RollCall $rollCall, array $person, string $source): RollCallEntry
    {
        return $rollCall->entries()->create([
            'visit_id' => $person['visit_id'],
            'visitor_id' => $person['visitor_id'],
            'source' => $source,
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'company' => $person['company'],
            'visitor_type' => $person['visitor_type'],
            'host_name' => $person['host_name'],
            'checked_in_at' => $person['checked_in_at'],
            'safety_notes' => $person['safety'] !== [] ? $person['safety'] : null,
        ]);
    }

    private function assertOpen(RollCall $rollCall): void
    {
        if (! $rollCall->isOpen()) {
            throw new InvalidArgumentException('This roll call has ended.');
        }
    }
}
