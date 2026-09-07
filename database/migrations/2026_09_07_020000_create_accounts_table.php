<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->decimal('current_balance', 15, 2);
            $table->timestamp('balance_updated_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'is_active']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_current_balance_check CHECK (current_balance >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
