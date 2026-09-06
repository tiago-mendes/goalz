<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('amount', 15, 2);
            $table->unsignedTinyInteger('day_of_month');
            $table->date('start_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fixed_expenses ADD CONSTRAINT fixed_expenses_day_check CHECK (day_of_month BETWEEN 1 AND 31)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_expenses');
    }
};
