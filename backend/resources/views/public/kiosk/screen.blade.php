{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@php
    use App\VisitorWorkflow\Models\FormField;
    use App\VisitorWorkflow\Models\VisitorType;
    $idleMs = max(15, (int) $kiosk->idle_timeout_seconds) * 1000;
@endphp
<div
    class="mx-auto flex min-h-screen max-w-3xl flex-col gap-6 px-6 py-8"
    x-data="{
        timer: null,
        arm() {
            clearTimeout(this.timer);
            const step = $wire.step;
            if (step === 'welcome') return;
            const ms = step === 'done' ? 10000 : {{ $idleMs }};
            this.timer = setTimeout(() => $wire.restart(), ms);
        },
    }"
    x-init="arm(); $watch('$wire.step', () => arm())"
    @pointerdown.window="arm()"
    @keydown.window="arm()"
>
    <header class="flex items-center justify-between gap-4">
        @if ($logo)
            <img src="{{ asset($logo) }}" alt="{{ config('branding.name') }}" class="h-14 w-auto">
        @else
            <span class="text-2xl font-semibold">{{ config('branding.name') }}</span>
        @endif
        @if (! in_array($step, ['welcome', 'done'], true))
            <button type="button" wire:click="restart" class="btn btn-ghost kiosk-tap">{{ __('Abbrechen') }}</button>
        @endif
    </header>

    @error('submit')
        <div role="alert" class="alert alert-error text-lg">{{ $message }}</div>
    @enderror

    <main class="card flex-1 bg-base-100 shadow-sm">
        <div class="card-body gap-6">

            @if ($step === 'welcome')
                <div class="flex flex-1 flex-col items-center justify-center gap-6 text-center">
                    <h1 class="text-4xl font-bold">{{ $welcomeHeading }}</h1>
                    @if ($welcomeMessage)<p class="max-w-xl text-xl text-base-content/80">{{ $welcomeMessage }}</p>@endif
                    <p class="text-lg text-base-content/60">{{ $siteName }}</p>
                    <button type="button" wire:click="start" class="btn btn-primary btn-lg kiosk-tap w-full max-w-md text-2xl">{{ $signInLabel }}</button>
                    <p class="text-base text-base-content/60">{{ __('Zum Abmelden wenden Sie sich bitte an den Empfang.') }}</p>
                </div>

            @elseif ($step === 'type')
                <h1 class="text-3xl font-semibold">{{ __('Was führt Sie zu uns?') }}</h1>
                @error('type')<p class="text-error">{{ $message }}</p>@enderror
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($types as $type)
                        <button type="button" wire:click="chooseType('{{ $type->slug }}')" class="btn btn-outline kiosk-tap h-auto justify-start py-5 text-left">
                            <span class="flex flex-col items-start">
                                <span class="text-xl font-semibold">{{ $type->name }}</span>
                                @if ($type->description)<span class="text-sm font-normal opacity-70">{{ $type->description }}</span>@endif
                            </span>
                        </button>
                    @endforeach
                </div>

            @elseif ($step === 'details')
                <h1 class="text-3xl font-semibold">{{ __('Ihre Angaben') }}</h1>
                <form wire:submit="submitDetails" class="grid gap-4 sm:grid-cols-2">
                    @foreach (['first_name' => 'given-name', 'name' => 'family-name', 'email' => 'email', 'phone' => 'tel', 'company' => 'organization'] as $field => $autocomplete)
                        <label class="form-control {{ in_array($field, ['email', 'company'], true) ? 'sm:col-span-2' : '' }}">
                            <span class="label-text mb-1 text-lg">{{ $detailLabels[$field] }}@if ($detailRequired[$field]) *@endif</span>
                            <input type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" autocomplete="{{ $autocomplete }}"
                                   wire:model="details.{{ $field }}" class="input input-bordered input-lg w-full @error('details.'.$field) input-error @enderror">
                            @error('details.'.$field)<span class="mt-1 text-error">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <div class="flex gap-3 sm:col-span-2">
                        <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap">{{ __('Zurück') }}</button>
                        <button type="submit" class="btn btn-primary kiosk-tap flex-1">{{ __('Weiter') }}</button>
                    </div>
                </form>

            @elseif ($step === 'host')
                <h1 class="text-3xl font-semibold">{{ __('Wen besuchen Sie?') }}</h1>
                <input type="search" wire:model.live.debounce.300ms="hostQuery" autocomplete="off" placeholder="{{ __('Namen eingeben') }}"
                       class="input input-bordered input-lg w-full" autofocus>
                @error('host')<p class="text-error">{{ $message }}</p>@enderror
                <div class="flex flex-col gap-3">
                    @foreach ($hostResults as $index => $option)
                        <button type="button" wire:click="chooseHost({{ $index }})" wire:key="host-{{ $index }}" class="btn btn-outline kiosk-tap justify-start">{{ $option['name'] }}</button>
                    @endforeach
                    @if (mb_strlen(trim($hostQuery)) >= 2 && $hostResults === [])
                        <p class="text-base-content/70">{{ __('Niemand gefunden. Bitte prüfen Sie die Schreibweise oder wenden Sie sich an den Empfang.') }}</p>
                    @endif
                </div>
                <div class="mt-auto flex gap-3">
                    <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap">{{ __('Zurück') }}</button>
                    @if ($visitorType && $visitorType->host_requirement === VisitorType::HOST_OPTIONAL)
                        <button type="button" wire:click="skipHost" class="btn btn-outline kiosk-tap flex-1">{{ __('Ich weiß es nicht') }}</button>
                    @endif
                </div>

            @elseif ($step === 'questions')
                <h1 class="text-3xl font-semibold">{{ __('Ein paar Fragen') }}</h1>
                <form wire:submit="submitQuestions" class="flex flex-col gap-6">
                    @foreach ($fields as $field)
                        @php($model = 'answers.'.$field->key)
                        <div wire:key="field-{{ $field->key }}" class="form-control gap-2">
                            @if (! in_array($field->type, [FormField::TYPE_CHECKBOX, FormField::TYPE_ACKNOWLEDGEMENT], true))
                                <span class="text-lg font-medium">{{ $field->label }}@if ($field->is_required_here) *@endif</span>
                            @endif
                            @if ($field->help_text)<span class="text-base-content/70">{{ $field->help_text }}</span>@endif

                            @switch($field->type)
                                @case(FormField::TYPE_LONG_TEXT)
                                    <textarea wire:model="{{ $model }}" rows="4" class="textarea textarea-bordered text-lg"></textarea>
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
                                    <select wire:model.live="{{ $model }}" class="select select-bordered select-lg w-full">
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
                                    <span wire:loading wire:target="{{ $model }}" class="text-base-content/70">{{ __('Wird hochgeladen …') }}</span>
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
                                           wire:model.blur="{{ $model }}" class="input input-bordered input-lg w-full">
                            @endswitch

                            @error($model)<span class="text-error">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                    <div class="flex gap-3">
                        <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap">{{ __('Zurück') }}</button>
                        <button type="submit" class="btn btn-primary kiosk-tap flex-1" wire:loading.attr="disabled">{{ __('Weiter') }}</button>
                    </div>
                </form>

            @elseif ($step === 'policies')
                <h1 class="text-3xl font-semibold">{{ __('Bitte lesen und bestätigen') }}</h1>
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
                    <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap">{{ __('Zurück') }}</button>
                    <button type="button" wire:click="submitPolicies" class="btn btn-primary kiosk-tap flex-1">{{ __('Weiter') }}</button>
                </div>

            @elseif ($step === 'confirm')
                <h1 class="text-3xl font-semibold">{{ __('Alles richtig?') }}</h1>
                <dl class="grid gap-3 text-xl sm:grid-cols-[auto_1fr]">
                    <dt class="text-base-content/60">{{ __('Name') }}</dt>
                    <dd class="font-medium">{{ trim(($details['first_name'] ?? '').' '.($details['name'] ?? '')) }}</dd>
                    @if ($visitorType)
                        <dt class="text-base-content/60">{{ __('Besuchertyp') }}</dt>
                        <dd class="font-medium">{{ $visitorType->name }}</dd>
                    @endif
                    @if ($hostName)
                        <dt class="text-base-content/60">{{ __('Sie besuchen') }}</dt>
                        <dd class="font-medium">{{ $hostName }}</dd>
                    @endif
                </dl>
                @if ($instructions)<p class="rounded-box bg-base-200 p-4 text-lg">{{ $instructions }}</p>@endif
                <div class="mt-auto flex gap-3">
                    <button type="button" wire:click="back" class="btn btn-ghost kiosk-tap">{{ __('Zurück') }}</button>
                    <button type="button" wire:click="finish" wire:loading.attr="disabled" class="btn btn-primary kiosk-tap flex-1 text-2xl">
                        <span wire:loading.remove wire:target="finish">{{ $signInLabel }}</span>
                        <span wire:loading wire:target="finish" class="loading loading-spinner"></span>
                    </button>
                </div>

            @elseif ($step === 'done')
                <div class="flex flex-1 flex-col items-center justify-center gap-6 text-center">
                    <h1 class="text-4xl font-bold">{{ __('Danke, :name!', ['name' => $doneFirstName]) }}</h1>
                    <p class="text-2xl">{{ $confirmation ?: __('Sie sind angemeldet.') }}</p>
                    @if ($doneHostName)
                        <p class="text-xl text-base-content/70">{{ __(':host wurde über Ihre Ankunft informiert.', ['host' => $doneHostName]) }}</p>
                    @endif
                    <button type="button" wire:click="restart" class="btn btn-primary kiosk-tap w-full max-w-md">{{ __('Fertig') }}</button>
                </div>
            @endif

        </div>
    </main>
</div>
