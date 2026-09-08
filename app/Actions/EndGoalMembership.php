<?php

namespace App\Actions;

use App\GoalMembershipStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\GoalMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EndGoalMembership
{
    public function handle(User $actor, int $goalId, int $memberUserId): void
    {
        DB::transaction(function () use ($actor, $goalId, $memberUserId): void {
            $accountIds = Account::query()
                ->where('user_id', $memberUserId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $goal = Goal::query()->whereKey($goalId)->lockForUpdate()->first();
            abort_if($goal === null, 404);

            $isOwnerRemovingMember = $goal->user_id === $actor->id && $memberUserId !== $actor->id;
            $isMemberLeaving = $memberUserId === $actor->id && $goal->user_id !== $actor->id;
            abort_unless($isOwnerRemovingMember || $isMemberLeaving, 404);

            $membership = GoalMembership::query()
                ->whereBelongsTo($goal)
                ->where('user_id', $memberUserId)
                ->where('status', GoalMembershipStatus::Accepted)
                ->lockForUpdate()
                ->first();
            abort_if($membership === null, 404);

            GoalAccountAllocation::query()
                ->whereBelongsTo($goal)
                ->whereIn('account_id', $accountIds)
                ->delete();
            $membership->delete();
        }, attempts: 3);
    }
}
