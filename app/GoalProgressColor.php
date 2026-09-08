<?php

namespace App;

use Brick\Math\BigDecimal;

final class GoalProgressColor
{
    public static function classes(string $percentage): string
    {
        $progress = BigDecimal::of($percentage);

        if ($progress->isLessThanOrEqualTo('15')) {
            return 'bg-red-600';
        }

        if ($progress->isLessThanOrEqualTo('25')) {
            return 'bg-orange-500';
        }

        if ($progress->isLessThanOrEqualTo('50')) {
            return 'bg-yellow-400';
        }

        if ($progress->isLessThanOrEqualTo('75')) {
            return 'bg-teal-500';
        }

        if ($progress->isLessThanOrEqualTo('90')) {
            return 'bg-sky-400';
        }

        if ($progress->isLessThan('100')) {
            return 'bg-blue-700';
        }

        return 'bg-green-600';
    }
}
