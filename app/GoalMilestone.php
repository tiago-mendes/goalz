<?php

namespace App;

enum GoalMilestone: int
{
    case TwentyFive = 25;
    case Fifty = 50;
    case SeventyFive = 75;
    case OneHundred = 100;

    public function label(): string
    {
        return $this->value.'%';
    }

    public function celebrationTitle(): string
    {
        return match ($this) {
            self::TwentyFive => '25% reached!',
            self::Fifty => 'Halfway there!',
            self::SeventyFive => '75% reached!',
            self::OneHundred => 'Goal funded!',
        };
    }
}
