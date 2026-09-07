<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('payment_account_id')->nullable()->after('fixed_expense_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('credit_card_id')->nullable()->after('payment_account_id')->constrained()->restrictOnDelete();
            $table->index(['credit_card_id', 'expense_date', 'deleted_by_user_at'], 'expenses_credit_card_bill_index');
        });

        Schema::table('fixed_expenses', function (Blueprint $table): void {
            $table->foreignId('payment_account_id')->nullable()->after('expense_category_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('credit_card_id')->nullable()->after('payment_account_id')->constrained()->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_payment_source_check CHECK (payment_account_id IS NULL OR credit_card_id IS NULL)');
            DB::statement('ALTER TABLE fixed_expenses ADD CONSTRAINT fixed_expenses_payment_source_check CHECK (payment_account_id IS NULL OR credit_card_id IS NULL)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE expenses DROP CONSTRAINT expenses_payment_source_check');
            DB::statement('ALTER TABLE fixed_expenses DROP CONSTRAINT fixed_expenses_payment_source_check');
        }

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex('expenses_credit_card_bill_index');
            $table->dropConstrainedForeignId('credit_card_id');
            $table->dropConstrainedForeignId('payment_account_id');
        });

        Schema::table('fixed_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credit_card_id');
            $table->dropConstrainedForeignId('payment_account_id');
        });
    }
};
