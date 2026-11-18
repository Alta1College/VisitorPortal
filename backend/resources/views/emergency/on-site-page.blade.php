<div class="grid min-w-0 gap-5" wire:poll.15s>
    <section class="flex flex-wrap items-end justify-between gap-4 rounded-box border border-base-300 bg-base-100 p-5">
        <div class="flex flex-col gap-2">
            @if ($sites->count() > 1)
                <label class="text-sm font-medium text-base-content/70" for="on-site-site">{{ __('Standort') }}</label>
                <select id="on-site-site" wire:model.live="siteId" class="select select-bordered">
                    @foreach ($sites as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            @else
                <span class="text-sm font-medium text-base-content/70">{{ $site->name }}</span>
            @endif
            <p class="text-3xl font-bold">{{ trans_choice(':count Person auf dem Gelände|:count Personen auf dem Gelände', count($people), ['count' => count($people)]) }}</p>
        </div>

        @if ($openRollCall)
            <a href="{{ route('reception.roll-calls.show', $openRollCall) }}" class="btn btn-error btn-lg">
                {{ __('Laufende Anwesenheitskontrolle öffnen') }}
            </a>
        @else
            <div class="flex flex-wrap gap-2" x-data>
                <button type="button" class="btn btn-error btn-lg"
                        x-on:click="if (confirm(@js(__('Anwesenheitskontrolle (Notfall) jetzt starten? Alle aktuell angemeldeten Personen werden erfasst.')))) $wire.startRollCall('emergency')">
                    {{ __('Anwesenheitskontrolle starten') }}
                </button>
                <button type="button" class="btn btn-outline btn-lg"
                        x-on:click="if (confirm(@js(__('Übung starten? Alle aktuell angemeldeten Personen werden erfasst.')))) $wire.startRollCall('drill')">
                    {{ __('Übung starten') }}
                </button>
            </div>
        @endif
    </section>

    <section class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Besuchertyp') }}</th>
                    <th>{{ __('Gastgeber') }}</th>
                    <th>{{ __('Angemeldet') }}</th>
                    <th>{{ __('Hinweise') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $person)
                    <tr wire:key="person-{{ $person['visit_id'] }}-{{ $person['visitor_id'] }}">
                        <td>
                            <div class="font-semibold">{{ trim($person['first_name'].' '.$person['last_name']) }}</div>
                            @if ($person['company'])<div class="text-sm text-base-content/70">{{ $person['company'] }}</div>@endif
                        </td>
                        <td>{{ $person['visitor_type'] ?? '—' }}</td>
                        <td>{{ $person['host_name'] ?? '—' }}</td>
                        <td>
                            {{ $person['earlier_day'] ? $person['checked_in_at']->format('d.m. H:i') : $person['checked_in_at']->format('H:i') }}
                            @if ($person['earlier_day'])
                                <div class="text-sm font-semibold text-warning">{{ __('Seit einem früheren Tag – evtl. Abmeldung vergessen') }}</div>
                            @endif
                        </td>
                        <td>
                            @foreach ($person['safety'] as $label => $value)
                                <div class="text-sm"><span class="text-base-content/70">{{ $label }}:</span> <strong>{{ $value }}</strong></div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-base-content/70">{{ __('Derzeit ist niemand angemeldet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @if ($history->isNotEmpty())
        <section class="rounded-box border border-base-300 bg-base-100 p-5">
            <h2 class="mb-3 text-lg font-semibold">{{ __('Frühere Anwesenheitskontrollen') }}</h2>
            <ul class="divide-y divide-base-200">
                @foreach ($history as $entry)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            <span class="badge {{ $entry->kind === 'emergency' ? 'badge-error' : 'badge-ghost' }}">{{ $kinds[$entry->kind] ?? $entry->kind }}</span>
                            {{ $entry->started_at->timezone($site->timezone ?: config('app.timezone'))->format('d.m.Y H:i') }}
                            @if ($entry->isOpen())<strong class="text-error">{{ __('läuft') }}</strong>@endif
                        </span>
                        <a href="{{ route('reception.roll-calls.show', $entry) }}" class="link">{{ __('Öffnen') }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
