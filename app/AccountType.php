<?php

namespace App;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Investment = 'investment';
    case Cash = 'cash';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Checking',
            self::Savings => 'Savings',
            self::Investment => 'Investment',
            self::Cash => 'Cash',
            self::Other => 'Other',
        };
    }
}
