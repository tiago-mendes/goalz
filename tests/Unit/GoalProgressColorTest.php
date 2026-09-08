<?php

namespace Tests\Unit;

use App\GoalProgressColor;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GoalProgressColorTest extends TestCase
{
    #[TestWith(['0', 'bg-red-600'])]
    #[TestWith(['15', 'bg-red-600'])]
    #[TestWith(['15.1', 'bg-orange-500'])]
    #[TestWith(['25', 'bg-orange-500'])]
    #[TestWith(['25.1', 'bg-yellow-400'])]
    #[TestWith(['50', 'bg-yellow-400'])]
    #[TestWith(['50.1', 'bg-teal-500'])]
    #[TestWith(['75', 'bg-teal-500'])]
    #[TestWith(['75.1', 'bg-sky-400'])]
    #[TestWith(['90', 'bg-sky-400'])]
    #[TestWith(['90.1', 'bg-blue-700'])]
    #[TestWith(['99.9', 'bg-blue-700'])]
    #[TestWith(['100', 'bg-green-600'])]
    #[TestWith(['120', 'bg-green-600'])]
    public function test_progress_percentage_maps_to_the_required_color(string $percentage, string $classes): void
    {
        $this->assertSame($classes, GoalProgressColor::classes($percentage));
    }
}
