<div class="grid min-w-0 gap-4" @if ($rollCall->isOpen()) wire:poll.5s @endif>
    <section class="rounded-box border-2 {{ $rollCall->isOpen() ? 'border-error' : 'border-base-300' }} bg-base-100 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-base-content/70">
                    {{ $rollCall->site->name }} · {{ $kinds[$rollCall->kind] ?? $rollCall->kind }} ·
                    {{ __('gestartet :time', ['time' => $rollCall->started_at->timezone($timezone)->format('d.m.Y H:i')]) }}
                    @if (! $rollCall->isOpen())
                        · {{ __('beendet :time', ['time' => $rollCall->ended_at->timezone($timezone)->format('H:i')]) }}
                    @endif
                </p>
                <p class="text-2xl font-bold {{ $counts['unaccounted'] > 0 ? 'text-error' : 'text-success' }}">
                    {{ trans_choice(':count Person nicht erfasst|:count Personen nicht erfasst', $counts['unaccounted'], ['count' => $counts['unaccounted']]) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('reception.roll-calls.print', $rollCall) }}" target="_blank" class="btn btn-outline">{{ __('Drucken') }}</a>
                @if ($rollCall->isOpen())
                    <button type="button" class="btn btn-neutral" x-data
                            x-on:click="if (confirm(@js(__('Anwesenheitskontrolle beenden? Danach kann nichts mehr geändert werden.')))) $wire.end()">
                        {{ __('Beenden') }}
                    </button>
                @endif
            </div>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
            @foreach (['unaccounted' => 'bg-error text-error-content', 'accounted' => 'bg-success text-success-content', 'left_site' => 'bg-base-300'] as $status => $classes)
                <button type="button" wire:click="$set('filter', '{{ $status }}')"
                        class="rounded-box p-2 {{ $classes }} {{ $filter === $status ? 'ring-4 ring-base-content/40' : '' }}">
                    <span class="block text-2xl font-bold">{{ $counts[$status] }}</span>
                    <span class="text-sm">{{ $statusLabels[$status] }}</span>
                </button>
            @endforeach
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" wire:click="$set('filter', 'all')" class="btn btn-sm {{ $filter === 'all' ? 'btn-primary' : 'btn-ghost' }}">{{ __('Alle anzeigen') }}</button>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Name suchen') }}" class="input input-bordered input-sm min-w-0 flex-1">
        </div>
    </section>

    <section class="grid gap-2">
        @forelse ($entries as $entry)
            <article wire:key="entry-{{ $entry->id }}" class="rounded-box border border-base-300 bg-base-100 p-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-lg font-semibold">{{ $entry->fullName() }}</p>
                        <p class="text-sm text-base-content/70">
                            {{ collect([$entry->visitor_type, $entry->company, $entry->host_name ? __('besucht :host', ['host' => $entry->host_name]) : null, $entry->checked_in_at?->timezone($timezone)->format('H:i')])->filter()->implode(' · ') }}
                        </p>
                        @if ($entry->source !== 'snapshot')
                            <p class="text-sm font-semibold text-info">{{ $entry->source === 'late' ? __('Nach dem Start angemeldet') : __('Von Hand hinzugefügt') }}</p>
                        @endif
                        @if (isset($signedOut[$entry->id]))
                            <p class="text-sm font-semibold text-warning">{{ __('Abgemeldet um :time', ['time' => $signedOut[$entry->id]]) }}</p>
                        @endif
                        @foreach ($entry->safety_notes ?? [] as $label => $value)
                            <p class="text-sm"><span class="text-base-content/70">{{ $label }}:</span> <strong>{{ $value }}</strong></p>
                        @endforeach
                    </div>
                    <span class="badge {{ ['unaccounted' => 'badge-error', 'accounted' => 'badge-success', 'left_site' => 'badge-ghost'][$entry->status] }}">{{ $statusLabels[$entry->status] }}</span>
                </div>
                @if ($rollCall->isOpen())
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <button type="button" wire:click="mark({{ $entry->id }}, 'accounted')" class="btn btn-success {{ $entry->status === 'accounted' ? '' : 'btn-outline' }}">{{ __('Anwesend') }}</button>
                        <button type="button" wire:click="mark({{ $entry->id }}, 'left_site')" class="btn {{ $entry->status === 'left_site' ? 'btn-neutral' : 'btn-outline' }}">{{ __('Gegangen') }}</button>
                        <button type="button" wire:click="mark({{ $entry->id }}, 'unaccounted')" class="btn btn-error {{ $entry->status === 'unaccounted' ? '' : 'btn-outline' }}">{{ __('Offen') }}</button>
                    </div>
                @elseif ($entry->status_changed_at)
                    <p class="mt-1 text-xs text-base-content/60">{{ $entry->status_changed_at->timezone($timezone)->format('H:i') }}</p>
                @endif
            </article>
        @empty
            <p class="rounded-box border border-base-300 bg-base-100 p-6 text-center text-base-content/70">{{ __('Niemand in dieser Ansicht.') }}</p>
        @endforelse
    </section>

    @if ($rollCall->isOpen())
        @if ($lateArrivals !== [])
            <section class="rounded-box border border-info bg-base-100 p-4">
                <h2 class="mb-2 font-semibold">{{ __('Nach dem Start angemeldet') }}</h2>
                @foreach ($lateArrivals as $person)
                    <div wire:key="late-{{ $person['visit_id'] }}-{{ $person['visitor_id'] }}" class="flex items-center justify-between gap-2 border-t border-base-200 py-2 first:border-t-0">
                        <span>{{ trim($person['first_name'].' '.$person['last_name']) }} <span class="text-sm text-base-content/70">{{ $person['checked_in_at']->format('H:i') }}</span></span>
                        <button type="button" wire:click="addLate({{ $person['visit_id'] }}, {{ $person['visitor_id'] }})" class="btn btn-sm btn-info">{{ __('Zur Liste hinzufügen') }}</button>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="rounded-box border border-base-300 bg-base-100 p-4">
            <h2 class="mb-2 font-semibold">{{ __('Person ohne Anmeldung hinzufügen') }}</h2>
            <form wire:submit="addManual" class="flex flex-wrap gap-2">
                <input type="text" wire:model="manualName" placeholder="{{ __('Name') }}" class="input input-bordered min-w-0 flex-1">
                <input type="text" wire:model="manualNote" placeholder="{{ __('Firma oder Notiz (optional)') }}" class="input input-bordered min-w-0 flex-1">
                <button type="submit" class="btn btn-primary">{{ __('Hinzufügen') }}</button>
            </form>
            @error('manualName')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
        </section>
    @endif
</div>
