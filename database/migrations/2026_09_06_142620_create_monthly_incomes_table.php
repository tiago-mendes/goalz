<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_incomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['user_id', 'year', 'month']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE monthly_incomes ADD CONSTRAINT monthly_incomes_month_check CHECK (month BETWEEN 1 AND 12)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_incomes');
    }
};
