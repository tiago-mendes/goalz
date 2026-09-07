<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('occurrence_year')->nullable();
            $table->unsignedTinyInteger('occurrence_month')->nullable();
            $table->string('name', 100);
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->timestamp('deleted_by_user_at')->nullable();
            $table->timestamps();
            $table->unique(['fixed_expense_id', 'occurrence_year', 'occurrence_month'], 'expenses_recurring_identity_unique');
            $table->index(['user_id', 'expense_date']);
            $table->index(['user_id', 'occurrence_year', 'occurrence_month']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_recurrence_check CHECK (
                (fixed_expense_id IS NULL AND occurrence_year IS NULL AND occurrence_month IS NULL)
                OR (fixed_expense_id IS NOT NULL AND occurrence_year IS NOT NULL AND occurrence_month IS NOT NULL
                    AND occurrence_year BETWEEN 1000 AND 9999 AND occurrence_month BETWEEN 1 AND 12)
            )');
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
