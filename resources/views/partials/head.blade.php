<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Goalz') : config('app.name', 'Goalz') }}
</title>

<link rel="icon" href="/favicon.ico?v=goalz-1" sizes="any">
<link rel="icon" href="/favicon.svg?v=goalz-1" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=goalz-1">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
