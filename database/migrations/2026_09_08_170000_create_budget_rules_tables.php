<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('mode', 20);
            $table->date('starts_month');
            $table->date('ends_month')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expense_category_id', 'starts_month']);
            $table->index(['user_id', 'expense_category_id', 'ends_month']);
        });

        Schema::create('budget_rule_months', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_rule_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->unique(['budget_rule_id', 'month']);
            $table->index(['month', 'budget_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_rule_months');
        Schema::dropIfExists('budget_rules');
    }
};
