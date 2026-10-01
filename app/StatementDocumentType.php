<?php

namespace App;

enum StatementDocumentType: string
{
    case BankStatement = 'bank_statement';
    case CreditCardBill = 'credit_card_bill';

    public function label(): string
    {
        return match ($this) {
            self::BankStatement => 'Bank Statement',
            self::CreditCardBill => 'Credit Card Bill',
        };
    }
}
