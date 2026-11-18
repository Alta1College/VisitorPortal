{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@php
    use App\VisitorWorkflow\Models\FormField;
    use App\VisitorWorkflow\Models\VisitorType;
    $idleMs = $idleSeconds !== null ? $idleSeconds * 1000 : null;
@endphp
<div
    class="brand-on-primary flex min-h-dvh flex-col px-6 py-6 sm:px-12 sm:py-10"
    x-data="{
        timer: null,
        arm() {
            clearTimeout(this.timer);
            const step = $wire.step;
            if (step === 'welcome') return;
            @if ($mode !== 'kiosk')
            return; // the visitor's own phone: nothing to clear for the next person
            @endif
            const ms = step === 'done' ? 10000 : {{ $idleMs ?? 90000 }};
            this.timer = setTimeout(() => $wire.restart(), ms);
        },
    }"
    x-init="arm(); $watch('$wire.step', () => arm())"
    @pointerdown.window="arm()"
    @keydown.window="arm()"
>
    <header class="flex items-center justify-between gap-4">
        @if ($logo)
            <img src="{{ asset($logo) }}" alt="{{ config('branding.name') }}" class="h-14 w-auto max-w-[60%] object-contain sm:h-20">
        @else
            <span class="text-3xl font-extrabold">{{ config('branding.name') }}</span>
        @endif
        @if (! in_array($step, ['welcome', 'done'], true))
            <button type="button" wire:click="restart" class="btn btn-outline rounded-full border-2 border-base-content/30 px-5 text-base font-bold text-base-content">{{ __('Abbrechen') }}</button>
        @endif
    </header>

    @if ($step === 'welcome')
        {{-- Visitor types straight away: one tap starts the sign-in. Polls so the greeting and types stay current. --}}
        <main wire:poll.300s class="flex flex-1 flex-col pt-[8vh]">
            <h1 class="kiosk-hero">@if ($greeting){{ $greeting }}<br>@endif{{ $welcomeHeading }}</h1>
            @if ($welcomeMessage)<p class="mt-4 max-w-2xl text-xl text-base-content">{{ $welcomeMessage }}</p>@endif

            @error('submit')<div role="alert" class="alert alert-error mt-6 text-lg">{{ $message }}</div>@enderror
            @error('type')<div role="alert" class="alert alert-error mt-6 text-lg">{{ $message }}</div>@enderror

            @if ($types === [])
                <p class="mt-10 max-w-xl text-2xl font-semibold">{{ __('Die Anmeldung ist hier gerade nicht möglich. Bitte wenden Sie sich an den Empfang.') }}</p>
            @else
                <p class="mt-5 text-lg font-semibold sm:text-xl">{{ $typeQuestion }}</p>
                <div @class(['mt-8 gap-5', 'flex flex-col items-start' => count($types) <= 5, 'grid grid-cols-1 sm:grid-cols-2' => count($types) > 5])>
                    @foreach ($types as $type)
                        <button type="button" wire:key="type-{{ $type->slug }}" wire:click="chooseType('{{ $type->slug }}')"
                                wire:loading.attr="disabled" class="btn btn-primary kiosk-pill">{{ $type->name }}</button>
                    @endforeach
                </div>
            @endif

            <footer class="mt-auto flex items-end justify-between gap-6 pt-10">
                <p class="max-w-sm text-base text-base-content">{{ __('Zum Abmelden wenden Sie sich bitte an den Empfang.') }}</p>
                @if ($welcomeQr)
                    <figure class="flex shrink-0 flex-col items-center gap-2">
                        <div class="size-36 rounded-2xl bg-white p-2 shadow-sm [&>svg]:size-full sm:size-40" aria-hidden="true">{!! $welcomeQr !!}</div>
                        <figcaption class="text-sm font-semibold">{{ __('Mobil anmelden') }}</figcaption>
                    </figure>
                @endif
            </footer>
        </main>
    @else
    @error('submit')
        <div role="alert" class="alert alert-error mx-auto mt-6 w-full max-w-3xl text-lg">{{ $message }}</div>
    @enderror

    <main class="mx-auto mt-6 flex w-full max-w-3xl flex-1 flex-col rounded-[2rem] bg-base-100 p-6 shadow-sm sm:p-10">
        <div class="flex flex-1 flex-col gap-8">

            @if ($step === 'details')
                <h1 class="kiosk-title">{{ $headings['details'] }}</h1>
                <form wire:submit="submitDetails" class="grid gap-4 sm:grid-cols-2">
                    @foreach ($detailFields as $field)
                        @php($autocomplete = ['first_name' => 'given-name', 'name' => 'family-name', 'email' => 'email', 'phone' => 'tel', 'company' => 'organization'][$field])
                        <label wire:key="detail-{{ $field }}" class="form-control {{ in_array($field, ['email', 'company'], true) ? 'sm:col-span-2' : '' }}">
                            <span class="mb-2 block text-lg font-semibold">{{ $detailLabels[$field] }}@if ($detailRequired[$field]) *@endif</span>
                            <input type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" autocomplete="{{ $autocomplete }}"
                                   wire:model="details.{{ $field }}" class="input input-bordered kiosk-field w-full @error('details.'.$field) input-error @enderror">
                            @error('details.'.$field)<span class="mt-1 text-error">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <div class="flex gap-3 sm:col-span-2">
                        <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap px-6">{{ __('Zurück') }}</button>
                        <button type="submit" class="btn btn-primary kiosk-tap flex-1">{{ __('Weiter') }}</button>
                    </div>
                </form>

            @elseif ($step === 'host')
                <h1 class="kiosk-title">{{ $headings['host'] }}</h1>
                <input type="search" wire:model.live.debounce.300ms="hostQuery" autocomplete="off" placeholder="{{ __('Namen eingeben') }}"
                       class="input input-bordered kiosk-field w-full" autofocus>
                @error('host')<p class="text-error">{{ $message }}</p>@enderror
                <div class="flex flex-col gap-3">
                    @foreach ($hostResults as $index => $option)
                        <button type="button" wire:click="chooseHost({{ $index }})" wire:key="host-{{ $index }}" class="btn btn-outline kiosk-tap justify-start border-2 text-2xl">{{ $option['name'] }}</button>
                    @endforeach
                    @if (mb_strlen(trim($hostQuery)) >= $minHostQuery && $hostResults === [])
                        <p class="text-base-content">{{ __('Niemand gefunden. Bitte prüfen Sie die Schreibweise oder wenden Sie sich an den Empfang.') }}</p>
                    @endif
                </div>
                <div class="mt-auto flex gap-3">
                    <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap px-6">{{ __('Zurück') }}</button>
                    @if ($visitorType && $visitorType->host_requirement === VisitorType::HOST_OPTIONAL)
                        <button type="button" wire:click="skipHost" class="btn btn-outline kiosk-tap flex-1">{{ __('Ich weiß es nicht') }}</button>
                    @endif
                </div>

            @elseif ($step === 'questions')
                <h1 class="kiosk-title">{{ $headings['questions'] }}</h1>
                <form wire:submit="submitQuestions" class="flex flex-col gap-6">
                    @foreach ($fields as $field)
                        @php($model = 'answers.'.$field->key)
                        <div wire:key="field-{{ $field->key }}" class="form-control gap-2">
                            @if (! in_array($field->type, [FormField::TYPE_CHECKBOX, FormField::TYPE_ACKNOWLEDGEMENT], true))
                                <span class="text-xl font-semibold">{{ $field->label }}@if ($field->is_required_here) *@endif</span>
                            @endif
                            @if ($field->help_text)<span class="text-base-content">{{ $field->help_text }}</span>@endif

                            @switch($field->type)
                                @case(FormField::TYPE_LONG_TEXT)
                                    <textarea wire:model="{{ $model }}" rows="4" class="textarea textarea-bordered rounded-2xl text-xl"></textarea>
                                    @break
                                @case(FormField::TYPE_YES_NO)
                                    <div class="grid grid-cols-2 gap-3">
                                        @foreach (['1' => __('Ja'), '0' => __('Nein')] as $value => $label)
                                            <label class="btn btn-outline kiosk-tap has-[:checked]:btn-primary">
                                                <input type="radio" class="hidden" value="{{ $value }}" wire:model.live="{{ $model }}">{{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                    @break
                                @case(FormField::TYPE_CHECKBOX)
                                @case(FormField::TYPE_ACKNOWLEDGEMENT)
                                    <label class="flex cursor-pointer items-start gap-4 rounded-box border border-base-300 p-4">
                                        <input type="checkbox" value="1" wire:model.live="{{ $model }}" class="checkbox checkbox-primary checkbox-lg mt-1">
                                        <span class="text-lg">{{ $field->label }}@if ($field->is_required_here) *@endif</span>
                                    </label>
                                    @break
                                @case(FormField::TYPE_DROPDOWN)
                                    <select wire:model.live="{{ $model }}" class="select select-bordered kiosk-field w-full">
                                        <option value="">{{ __('Bitte auswählen') }}</option>
                                        @foreach ($field->options ?? [] as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @case(FormField::TYPE_PHOTO)
                                @case(FormField::TYPE_FILE)
                                    <input type="file" wire:model="{{ $model }}" class="file-input file-input-bordered file-input-lg w-full"
                                           accept="{{ $field->type === FormField::TYPE_PHOTO ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp' }}"
                                           @if ($field->type === FormField::TYPE_PHOTO) capture="user" @endif>
                                    <span wire:loading wire:target="{{ $model }}" class="text-base-content">{{ __('Wird hochgeladen …') }}</span>
                                    @break
                                @case(FormField::TYPE_SIGNATURE)
                                    <div x-data="{
                                            drawing: false,
                                            ctx: null,
                                            init() { this.ctx = this.$refs.pad.getContext('2d'); this.ctx.lineWidth = 3; this.ctx.lineCap = 'round'; },
                                            pos(e) { const r = this.$refs.pad.getBoundingClientRect(); return [(e.clientX - r.left) * this.$refs.pad.width / r.width, (e.clientY - r.top) * this.$refs.pad.height / r.height]; },
                                            down(e) { this.drawing = true; this.ctx.beginPath(); this.ctx.moveTo(...this.pos(e)); },
                                            move(e) { if (!this.drawing) return; this.ctx.lineTo(...this.pos(e)); this.ctx.stroke(); },
                                            up() { if (!this.drawing) return; this.drawing = false; $wire.set('{{ $model }}', this.$refs.pad.toDataURL('image/png'), false); },
                                            clear() { this.ctx.clearRect(0, 0, this.$refs.pad.width, this.$refs.pad.height); $wire.set('{{ $model }}', null, false); },
                                        }" class="flex flex-col gap-2">
                                        <canvas x-ref="pad" width="900" height="300" class="w-full touch-none rounded-box border-2 border-dashed border-base-300 bg-white"
                                                @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up()" @pointerleave="up()"></canvas>
                                        <button type="button" @click="clear()" class="btn btn-ghost btn-sm self-start">{{ __('Unterschrift löschen') }}</button>
                                    </div>
                                    @break
                                @default
                                    <input type="{{ match ($field->type) { FormField::TYPE_EMAIL => 'email', FormField::TYPE_PHONE => 'tel', FormField::TYPE_NUMBER => 'number', FormField::TYPE_DATE => 'date', default => 'text' } }}"
                                           wire:model.blur="{{ $model }}" class="input input-bordered kiosk-field w-full">
                            @endswitch

                            @error($model)<span class="text-error">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                    <div class="flex gap-3">
                        <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap px-6">{{ __('Zurück') }}</button>
                        <button type="submit" class="btn btn-primary kiosk-tap flex-1" wire:loading.attr="disabled">{{ __('Weiter') }}</button>
                    </div>
                </form>

            @elseif ($step === 'policies')
                <h1 class="kiosk-title">{{ $headings['policies'] }}</h1>
                @error('policies')<p class="text-error">{{ $message }}</p>@enderror
                <div class="flex flex-col gap-6">
                    @foreach ($policies as $version)
                        <section wire:key="policy-{{ $version->content_hash }}" class="rounded-box border border-base-300 p-5">
                            <h2 class="mb-3 text-xl font-semibold">{{ $version->title }}</h2>
                            <div class="prose prose-lg max-h-72 max-w-none overflow-y-auto">{{ $version->renderedBody() }}</div>
                            <label class="mt-4 flex cursor-pointer items-start gap-4">
                                <input type="checkbox" wire:model.live="accepted.{{ $version->content_hash }}" class="checkbox checkbox-primary checkbox-lg mt-1">
                                <span class="text-lg">{{ $version->acceptance_label }}</span>
                            </label>
                            @error('policies.'.$version->content_hash)<p class="mt-2 text-error">{{ $message }}</p>@enderror
                        </section>
                    @endforeach
                </div>
                <div class="flex gap-3">
                    <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap px-6">{{ __('Zurück') }}</button>
                    <button type="button" wire:click="submitPolicies" class="btn btn-primary kiosk-tap flex-1">{{ __('Weiter') }}</button>
                </div>

            @elseif ($step === 'confirm')
                <h1 class="kiosk-title">{{ $headings['confirm'] }}</h1>
                <dl class="grid gap-3 text-xl sm:grid-cols-[auto_1fr]">
                    <dt class="text-base-content">{{ __('Name') }}</dt>
                    <dd class="font-medium">{{ trim(($details['first_name'] ?? '').' '.($details['name'] ?? '')) }}</dd>
                    @if ($visitorType)
                        <dt class="text-base-content">{{ __('Besuchertyp') }}</dt>
                        <dd class="font-medium">{{ $visitorType->name }}</dd>
                    @endif
                    @if ($hostName)
                        <dt class="text-base-content">{{ __('Sie besuchen') }}</dt>
                        <dd class="font-medium">{{ $hostName }}</dd>
                    @endif
                </dl>
                @if ($instructions)<p class="rounded-box bg-base-200 p-4 text-lg">{{ $instructions }}</p>@endif
                <div class="mt-auto flex gap-3">
                    <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap px-6">{{ __('Zurück') }}</button>
                    <button type="button" wire:click="finish" wire:loading.attr="disabled" class="btn btn-primary kiosk-tap flex-1 text-2xl">
                        <span wire:loading.remove wire:target="finish">{{ $signInLabel }}</span>
                        <span wire:loading wire:target="finish" class="loading loading-spinner"></span>
                    </button>
                </div>

            @elseif ($step === 'done')
                <div class="flex flex-1 flex-col items-center justify-center gap-6 text-center">
                    <span class="flex size-24 items-center justify-center rounded-full bg-primary text-primary-content" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="size-12"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                    </span>
                    <h1 class="kiosk-hero">{{ str_replace(':name', (string) $doneFirstName, $headings['done']) }}</h1>
                    <p class="text-2xl">{{ $confirmation ?: __('Sie sind angemeldet.') }}</p>
                    @if ($doneHostName)
                        <p class="text-xl text-base-content">{{ __(':host wurde über Ihre Ankunft informiert.', ['host' => $doneHostName]) }}</p>
                    @endif
                    <button type="button" wire:click="restart" class="btn btn-primary kiosk-tap w-full max-w-md">{{ __('Fertig') }}</button>
                </div>
            @endif

        </div>
    </main>
    @endif
</div>
