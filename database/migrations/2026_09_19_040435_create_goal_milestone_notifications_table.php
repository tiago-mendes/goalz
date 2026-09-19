<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goal_milestone_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('goal_milestone_achievement_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->foreign('goal_milestone_achievement_id', 'gmn_achievement_fk')
                ->references('id')->on('goal_milestone_achievements')->cascadeOnDelete();
            $table->unique(['goal_milestone_achievement_id', 'user_id'], 'gmn_achievement_user_unique');
            $table->index(['user_id', 'seen_at'], 'gmn_user_seen_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_milestone_notifications');
    }
};
