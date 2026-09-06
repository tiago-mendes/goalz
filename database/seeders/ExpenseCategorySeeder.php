<?php

namespace Database\Seeders;

use App\Actions\ProvisionExpenseCategories;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(ProvisionExpenseCategories $provision): void
    {
        User::select('id')->lazyById(200)->each(function (User $user) use ($provision): void {
            $provision->handle($user);
        });
    }
}
