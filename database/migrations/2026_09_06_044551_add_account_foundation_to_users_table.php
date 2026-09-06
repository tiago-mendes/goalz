<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('user');
            $table->boolean('is_active')->default(true);
            $table->decimal('default_monthly_income', total: 15, places: 2)->nullable();
            $table->string('currency', 3)->default('BRL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'is_active', 'default_monthly_income', 'currency']);
        });
    }
};
