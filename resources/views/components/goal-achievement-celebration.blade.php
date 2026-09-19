<?php

use App\Models\GoalMilestoneNotification;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function notification(): ?GoalMilestoneNotification
    {
        return auth()->user()->goalMilestoneNotifications()
            ->whereNull('seen_at')
            ->whereHas('achievement.goal', fn ($query) => $query->accessibleTo(auth()->user()))
            ->with([
                'achievement:id,goal_id,milestone_percentage,achieved_at',
                'achievement.goal:id,user_id,name,target_amount',
                'achievement.goal.goalAccountAllocations:id,goal_id,amount',
                'achievement.goal.rewards:id,goal_id,milestone_percentage,title,description',
            ])
            ->oldest('created_at')
            ->oldest('id')
            ->first();
    }

    public function acknowledge(int $notificationId): void
    {
        DB::transaction(function () use ($notificationId): void {
            $notification = auth()->user()->goalMilestoneNotifications()
                ->whereKey($notificationId)
                ->whereNull('seen_at')
                ->whereHas('achievement.goal', fn ($query) => $query->accessibleTo(auth()->user()))
                ->lockForUpdate()
                ->first();
            abort_if($notification === null, 404);

            $notification->seen_at = now();
            $notification->save();
        }, attempts: 3);

        unset($this->notification);
    }
};
?>

<div>
    @if ($notification = $this->notification)
        @php($achievement = $notification->achievement)
        @php($goal = $achievement->goal)
        @php($milestone = $achievement->milestone_percentage)
        @php($reward = $achievement->reward())

        @if ($milestone === \App\GoalMilestone::OneHundred)
            <div wire:key="goal-celebration-{{ $notification->id }}" class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="goal-celebration-title" x-data x-init="$nextTick(() => $refs.continue.focus())">
                <div class="goal-celebration goal-celebration--100 relative w-full max-w-lg overflow-hidden rounded-2xl bg-white p-8 text-center shadow-2xl dark:bg-zinc-900">
                    <div class="goal-confetti" aria-hidden="true">
                        @foreach (range(1, 12) as $piece)
                            <span style="--piece: {{ $piece }}"></span>
                        @endforeach
                    </div>
                    <div class="relative space-y-5">
                        <div class="text-5xl" aria-hidden="true">🎉</div>
                        <div>
                            <flux:heading size="xl" level="2" id="goal-celebration-title">{{ $milestone->celebrationTitle() }}</flux:heading>
                            <flux:text class="mt-2">{{ $goal->name }}</flux:text>
                        </div>
                        <p class="text-lg font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$goal->allocatedAmount()" /> / <x-money :currency="auth()->user()->currency" :amount="$goal->target_amount" /></p>
                        @if ($reward)
                            <div class="rounded-xl bg-green-50 p-4 text-left dark:bg-green-950/40">
                                <p class="font-semibold">Reward unlocked</p>
                                <p>{{ $reward->title }}</p>
                                @if ($reward->description)<flux:text>{{ $reward->description }}</flux:text>@endif
                            </div>
                        @endif
                        <flux:button x-ref="continue" variant="primary" wire:click="acknowledge({{ $notification->id }})" wire:loading.attr="disabled">Continue</flux:button>
                    </div>
                </div>
            </div>
        @else
            <div wire:key="goal-celebration-{{ $notification->id }}" class="goal-celebration goal-celebration--{{ $milestone->value }} fixed bottom-4 right-4 z-50 w-[calc(100%-2rem)] max-w-md rounded-xl border border-green-300 bg-white p-5 shadow-xl dark:border-green-800 dark:bg-zinc-900" role="status" aria-live="polite" x-data x-init="$nextTick(() => $refs.dismiss.focus())">
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading size="lg">{{ $milestone->celebrationTitle() }}</flux:heading>
                            <flux:text>{{ $goal->name }}</flux:text>
                        </div>
                        <flux:badge color="green">✓ {{ $milestone->label() }}</flux:badge>
                    </div>
                    @if ($reward)
                        <div class="rounded-lg bg-green-50 p-3 dark:bg-green-950/40">
                            <p class="font-semibold">Reward unlocked</p>
                            <p>{{ $reward->title }}</p>
                            @if ($reward->description)<flux:text>{{ $reward->description }}</flux:text>@endif
                        </div>
                    @endif
                    <div class="flex justify-end">
                        <flux:button x-ref="dismiss" size="sm" variant="primary" wire:click="acknowledge({{ $notification->id }})" wire:loading.attr="disabled">Dismiss</flux:button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
