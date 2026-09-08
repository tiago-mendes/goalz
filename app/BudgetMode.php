<?php

namespace App;

enum BudgetMode: string
{
    case Recurring = 'recurring';
    case SelectedMonths = 'selected_months';

    public function label(): string
    {
        return match ($this) {
            self::Recurring => 'Recurring',
            self::SelectedMonths => 'Selected months',
        };
    }
}
