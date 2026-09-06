<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProvisionExpenseCategories
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            foreach ([
                ['Food', 'shopping-cart', '#F97316'],
                ['Transport', 'truck', '#3B82F6'],
                ['Housing', 'home', '#8B5CF6'],
                ['Health', 'heart', '#EF4444'],
                ['Leisure', 'puzzle-piece', '#EC4899'],
                ['Education', 'academic-cap', '#14B8A6'],
                ['Subscriptions', 'arrow-path', '#EAB308'],
                ['Other', 'tag', '#64748B'],
            ] as [$name, $icon, $color]) {
                $user->expenseCategories()->firstOrCreate(['name' => $name], [
                    'icon' => $icon, 'color' => $color, 'is_active' => true,
                ]);
            }
        });
    }
}
