@php
    /** @var \App\Badges\BadgeContent $badge */
    /** @var \App\Badges\LabelFormat $format */
    // One CSS pixel = one printer dot (300 dpi). Black on white only: thermal labels.
    $longest = max(mb_strlen($badge->firstName), 1);
    $nameSize = match (true) {
        $longest <= 7 => 132,
        $longest <= 10 => 108,
        $longest <= 13 => 88,
        default => 70,
    };
    // Every badge has a photo slot (smaller name to make room).
    $nameSize = (int) round($nameSize * 0.8);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: {{ $format->widthDots }}px; height: {{ $format->lengthDots }}px; background: #fff; color: #000; overflow: hidden; }
    body {
        font-family: "Noto Sans", "Liberation Sans", "DejaVu Sans", Arial, sans-serif;
        display: flex; flex-direction: column;
        padding: 34px 40px 30px;
        -webkit-font-smoothing: none;
    }
    .top { display: flex; align-items: center; justify-content: space-between; gap: 24px; height: 110px; }
    .logo { max-height: 110px; max-width: 420px; object-fit: contain; object-position: left center; filter: grayscale(1) contrast(1.4); }
    .site { font-size: 30px; font-weight: 700; text-align: right; line-height: 1.15; max-width: 260px; }
    .type {
        margin-top: 28px; background: #000; color: #fff; border-radius: 18px;
        font-size: 62px; font-weight: 800; letter-spacing: 6px; text-transform: uppercase;
        text-align: center; padding: 14px 16px; line-height: 1.1;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .who { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; gap: 6px; padding: 18px 0; min-height: 0; }
    .photo { width: 270px; height: 300px; object-fit: cover; border-radius: 24px; border: 6px solid #000; margin-bottom: 18px; filter: grayscale(1) contrast(1.15); }
    .photo.missing { display: flex; align-items: center; justify-content: center; border-style: dashed; font-size: 34px; font-weight: 700; }
    .first { font-size: {{ $nameSize }}px; font-weight: 800; line-height: 1.02; overflow-wrap: anywhere; }
    .last { font-size: {{ (int) round($nameSize * 0.55) }}px; font-weight: 600; line-height: 1.1; overflow-wrap: anywhere; }
    .company { margin-top: 8px; font-size: 38px; font-weight: 500; overflow-wrap: anywhere; }
    .details { border-top: 5px solid #000; padding-top: 20px; display: grid; grid-template-columns: auto 1fr; column-gap: 22px; row-gap: 10px; font-size: 32px; line-height: 1.2; }
    .details dt { font-weight: 500; }
    .details dd { font-weight: 800; overflow-wrap: anywhere; }
    .footer { margin-top: 22px; font-size: 25px; line-height: 1.25; text-align: center; font-weight: 500; }
</style>
</head>
<body>
    <div class="top">
        @if ($badge->logo)
            <img class="logo" src="{{ $badge->logo }}" alt="">
        @else
            <span></span>
        @endif
        <div class="site">{{ $badge->siteName }}</div>
    </div>

    <div class="type">{{ $badge->typeName }}</div>

    <div class="who">
        @if ($badge->photo)
            <img class="photo" src="{{ $badge->photo }}" alt="">
        @else
            <div class="photo missing">{{ __('Kein Foto') }}</div>
        @endif
        <div class="first">{{ $badge->firstName }}</div>
        <div class="last">{{ $badge->lastName }}</div>
        @if ($badge->company)
            <div class="company">{{ $badge->company }}</div>
        @endif
    </div>

    <dl class="details">
        @if ($badge->hostName)
            <dt>{{ __('Besucht') }}</dt><dd>{{ $badge->hostName }}</dd>
        @endif
        <dt>{{ __('Datum') }}</dt><dd>{{ $badge->date }} · {{ $badge->time }}</dd>
        @foreach ($badge->fields as $label => $value)
            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
        @endforeach
    </dl>

    <p class="footer">{{ $badge->footer }}</p>
</body>
</html>
