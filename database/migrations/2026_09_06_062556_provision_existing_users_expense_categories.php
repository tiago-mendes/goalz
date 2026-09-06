<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['name' => 'Food', 'icon' => 'shopping-cart', 'color' => '#F97316'],
            ['name' => 'Transport', 'icon' => 'truck', 'color' => '#3B82F6'],
            ['name' => 'Housing', 'icon' => 'home', 'color' => '#8B5CF6'],
            ['name' => 'Health', 'icon' => 'heart', 'color' => '#EF4444'],
            ['name' => 'Leisure', 'icon' => 'puzzle-piece', 'color' => '#EC4899'],
            ['name' => 'Education', 'icon' => 'academic-cap', 'color' => '#14B8A6'],
            ['name' => 'Subscriptions', 'icon' => 'arrow-path', 'color' => '#EAB308'],
            ['name' => 'Other', 'icon' => 'tag', 'color' => '#64748B'],
        ];

        foreach (DB::table('users')->select('id')->lazyById(200) as $user) {
            $timestamp = now();
            $categories = [];

            foreach ($defaults as $default) {
                $categories[] = [
                    ...$default,
                    'user_id' => $user->id,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            DB::table('expense_categories')->insertOrIgnore($categories);
        }
    }

    /** Existing categories must survive rollback of the backfill. */
    public function down(): void {}
};
