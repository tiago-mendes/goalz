<?php

use App\Actions\ProvisionExpenseCategories;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $provision = app(ProvisionExpenseCategories::class);
        User::select('id')->lazyById(200)->each(function (User $user) use ($provision): void {
            $provision->handle($user);
        });
    }

    /** Existing categories must survive rollback of the backfill. */
    public function down(): void {}
};
