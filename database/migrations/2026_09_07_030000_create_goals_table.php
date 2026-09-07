<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('target_amount', 15, 2);
            $table->date('target_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE goals ADD CONSTRAINT goals_target_amount_check CHECK (target_amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
