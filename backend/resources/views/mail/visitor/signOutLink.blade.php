{{-- SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
<x-mail.message_component :message="$message">
# {{ __('Sie sind angemeldet') }}

{{ __('Hallo :name,', ['name' => $firstName]) }}

{{ __('Sie sind bei :site angemeldet. Wenn Sie gehen, melden Sie sich bitte mit diesem Link ab:', ['site' => $siteName]) }}

<x-mail::button :url="$url">
{{ __('Besuch beenden') }}
</x-mail::button>

{{ __('Der Link funktioniert einmal und ist :hours Stunden gültig. Sie können sich auch am Kiosk oder am Empfang abmelden.', ['hours' => $validHours]) }}

{{ __('Wenn Sie sich nicht angemeldet haben, können Sie diese E-Mail ignorieren.') }}
</x-mail.message_component>
