<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_bill_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('due_year');
            $table->unsignedTinyInteger('due_month');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            $table->unique(['credit_card_id', 'due_year', 'due_month'], 'credit_card_bill_periods_due_month_unique');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE credit_card_bill_periods ADD CONSTRAINT credit_card_bill_periods_due_month_check CHECK (due_year BETWEEN 1000 AND 9999 AND due_month BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE credit_card_bill_periods ADD CONSTRAINT credit_card_bill_periods_date_order_check CHECK (start_date <= end_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_bill_periods');
    }
};
