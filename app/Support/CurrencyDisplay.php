<?php

namespace App\Support;

final class CurrencyDisplay
{
    public static function symbol(?string $currency): string
    {
        return $currency === 'BRL' ? 'R$' : ($currency ?? '');
    }

    public static function format(?string $currency, string $amount): string
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $absoluteAmount = $negative ? substr($amount, 1) : $amount;

        return ($negative ? '-' : '').self::symbol($currency).' '.$absoluteAmount;
    }
}
