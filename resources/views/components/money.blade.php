@props(['currency', 'amount'])
{{ \App\Support\CurrencyDisplay::format($currency, (string) $amount) }}
