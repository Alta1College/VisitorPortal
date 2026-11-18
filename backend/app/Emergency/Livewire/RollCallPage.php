<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Livewire;

use App\Emergency\OnSiteRegister;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Emergency\RollCalls;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The roll call board wardens use (phones included): mark each person, add late arrivals or
 * people who never signed in, and end the roll call. Refreshes every few seconds so several
 * wardens can work on the same list.
 */
class RollCallPage extends Component
{
    #[Locked]
    public int $rollCallId;

    public string $filter = RollCallEntry::UNACCOUNTED;

    public string $search = '';

    public string $manualName = '';

    public string $manualNote = '';

    public function mount(RollCall $rollCall): void
    {
        $this->rollCallId = $rollCall->getKey();
        $this->rollCall();

        if (! $rollCall->isOpen()) {
            $this->filter = 'all';
        }
    }

    public function mark(int $entryId, string $status): void
    {
        $entry = $this->openRollCall()->entries()->findOrFail($entryId);
        app(RollCalls::class)->mark($entry, $status, $this->user());
    }

    public function addLate(int $visitId, int $visitorId): void
    {
        app(RollCalls::class)->addLate($this->openRollCall(), $visitId, $visitorId, $this->user());
    }

    public function addManual(): void
    {
        $this->validate([
            'manualName' => ['required', 'string', 'max:191'],
            'manualNote' => ['nullable', 'string', 'max:191'],
        ], [], ['manualName' => __('Name'), 'manualNote' => __('Notiz')]);

        app(RollCalls::class)->addManual($this->openRollCall(), $this->manualName, $this->manualNote, $this->user());
        $this->reset(['manualName', 'manualNote']);
    }

    public function end(): void
    {
        app(RollCalls::class)->end($this->openRollCall(), $this->user());
        $this->filter = 'all';
    }

    public function render(): View
    {
        $rollCall = $this->rollCall();
        $timezone = $rollCall->site->timezone ?: (string) config('app.timezone');

        $entries = $rollCall->entries()
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->when(trim($this->search) !== '', function ($q): void {
                $like = '%'.addcslashes(mb_strtolower(trim($this->search)), '\\%_').'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(first_name) LIKE ?', [$like])->orWhereRaw('LOWER(last_name) LIKE ?', [$like])->orWhereRaw('LOWER(company) LIKE ?', [$like]));
            })
            ->orderByRaw("CASE status WHEN 'unaccounted' THEN 0 WHEN 'accounted' THEN 1 ELSE 2 END")
            ->orderBy('first_name')
            ->get();

        return view('emergency.roll-call-page', [
            'rollCall' => $rollCall,
            'entries' => $entries,
            'counts' => $rollCall->counts(),
            'signedOut' => $this->signedOutSince($rollCall, $timezone),
            'lateArrivals' => $rollCall->isOpen() ? app(RollCalls::class)->lateArrivals($rollCall) : [],
            'timezone' => $timezone,
            'statusLabels' => RollCallEntry::statusLabels(),
            'kinds' => RollCall::kinds(),
        ]);
    }

    /**
     * People on the list who signed out normally after the roll call started (a hint for wardens;
     * they stay on the list until marked).
     *
     * @return array<int, string> entry id => "10:42 (kiosk)"
     */
    private function signedOutSince(RollCall $rollCall, string $timezone): array
    {
        $entries = $rollCall->entries()->whereNotNull('visit_id')->get(['id', 'visit_id', 'visitor_id']);

        if ($entries->isEmpty()) {
            return [];
        }

        $outs = DB::table('visit_visitor')
            ->whereIn('visit_id', $entries->pluck('visit_id')->unique())
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '>=', $rollCall->started_at)
            ->get(['visit_id', 'visitor_id', 'checked_out_at', 'checked_out_via'])
            ->keyBy(fn ($row): string => $row->visit_id.'|'.$row->visitor_id);

        $hints = [];

        foreach ($entries as $entry) {
            $out = $outs[$entry->visit_id.'|'.$entry->visitor_id] ?? null;

            if ($out !== null) {
                $hints[$entry->getKey()] = Carbon::parse($out->checked_out_at)->timezone($timezone)->format('H:i').($out->checked_out_via ? ' ('.$out->checked_out_via.')' : '');
            }
        }

        return $hints;
    }

    private function rollCall(): RollCall
    {
        $rollCall = RollCall::query()->with('site')->findOrFail($this->rollCallId);
        abort_unless(app(OnSiteRegister::class)->canAccess($this->user(), $rollCall->site_id), 403);

        return $rollCall;
    }

    private function openRollCall(): RollCall
    {
        $rollCall = $this->rollCall();
        abort_unless($rollCall->isOpen(), 409);

        return $rollCall;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
