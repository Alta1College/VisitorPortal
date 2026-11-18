<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Anwesenheitskontrolle') }} – {{ $rollCall->site->name }}</title>
<style>
    body { font: 12pt/1.35 system-ui, sans-serif; color: #000; margin: 15mm; }
    h1 { font-size: 18pt; margin: 0 0 4mm; }
    p.meta { margin: 0 0 6mm; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #000; padding: 2mm; text-align: left; vertical-align: top; }
    th { background: #eee; }
    .box { width: 8mm; height: 8mm; border: 1.5px solid #000; display: inline-block; }
    .notes { font-size: 10pt; }
    @media print { body { margin: 10mm; } .noprint { display: none; } }
</style>
</head>
<body>
    <p class="noprint"><button onclick="window.print()">{{ __('Drucken') }}</button></p>
    <h1>{{ __('Anwesenheitskontrolle') }} – {{ $rollCall->site->name }}</h1>
    <p class="meta">
        {{ $kinds[$rollCall->kind] ?? $rollCall->kind }} ·
        {{ __('gestartet :time', ['time' => $rollCall->started_at->timezone($timezone)->format('d.m.Y H:i')]) }}
        @if ($rollCall->ended_at) · {{ __('beendet :time', ['time' => $rollCall->ended_at->timezone($timezone)->format('H:i')]) }} @endif
        · {{ __('Gedruckt :time', ['time' => now($timezone)->format('d.m.Y H:i')]) }}
    </p>
    <table>
        <thead>
            <tr>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Besuchertyp') }} / {{ __('Firma') }}</th>
                <th>{{ __('Gastgeber') }}</th>
                <th>{{ __('Hinweise') }}</th>
                <th>{{ __('Status') }}</th>
                <th>✓</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td><strong>{{ $entry->fullName() }}</strong></td>
                    <td>{{ collect([$entry->visitor_type, $entry->company])->filter()->implode(' / ') ?: '—' }}</td>
                    <td>{{ $entry->host_name ?? '—' }}</td>
                    <td class="notes">@foreach ($entry->safety_notes ?? [] as $label => $value){{ $label }}: {{ $value }}<br>@endforeach</td>
                    <td>{{ $statusLabels[$entry->status] }}</td>
                    <td><span class="box"></span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
