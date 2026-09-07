<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2);
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'recorded_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE account_balance_snapshots ADD CONSTRAINT account_balance_snapshots_balance_check CHECK (balance >= 0)');
        }

        DB::table('accounts')
            ->select(['id', 'current_balance', 'balance_updated_at', 'updated_at', 'created_at'])
            ->orderBy('id')
            ->chunkById(200, function ($accounts): void {
                $createdAt = now();
                $snapshots = [];

                foreach ($accounts as $account) {
                    $snapshots[] = [
                        'account_id' => $account->id,
                        'balance' => $account->current_balance,
                        'recorded_at' => $account->balance_updated_at ?? $account->updated_at ?? $account->created_at ?? $createdAt,
                        'created_at' => $createdAt,
                    ];
                }

                if ($snapshots !== []) {
                    DB::table('account_balance_snapshots')->insert($snapshots);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_balance_snapshots');
    }
};
