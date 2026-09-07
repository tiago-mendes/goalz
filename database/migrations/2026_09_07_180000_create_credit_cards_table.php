<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('cycle_start_day');
            $table->unsignedTinyInteger('due_day');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'is_active']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE credit_cards ADD CONSTRAINT credit_cards_cycle_start_day_check CHECK (cycle_start_day BETWEEN 1 AND 31)');
            DB::statement('ALTER TABLE credit_cards ADD CONSTRAINT credit_cards_due_day_check CHECK (due_day BETWEEN 1 AND 31)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
