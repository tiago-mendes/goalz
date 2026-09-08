<?php

namespace App\Actions;

use App\GoalMembershipStatus;
use App\Models\Goal;
use App\Models\GoalMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteGoalMember
{
    public function handle(User $owner, int $goalId, string $email): GoalMembership
    {
        $email = trim($email);

        return DB::transaction(function () use ($owner, $goalId, $email): GoalMembership {
            $goal = Goal::query()->whereKey($goalId)->whereBelongsTo($owner)->lockForUpdate()->first();
            abort_if($goal === null, 404);

            $invitedUser = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

            if ($invitedUser === null || ! $invitedUser->is_active) {
                throw ValidationException::withMessages(['inviteEmail' => 'Enter the email of an active registered user.']);
            }

            if ($invitedUser->id === $owner->id) {
                throw ValidationException::withMessages(['inviteEmail' => 'You cannot invite yourself.']);
            }

            $membership = GoalMembership::query()
                ->whereBelongsTo($goal)
                ->whereBelongsTo($invitedUser)
                ->lockForUpdate()
                ->first();

            if ($membership?->status === GoalMembershipStatus::Pending) {
                throw ValidationException::withMessages(['inviteEmail' => 'This user already has a pending invitation.']);
            }

            if ($membership?->status === GoalMembershipStatus::Accepted) {
                throw ValidationException::withMessages(['inviteEmail' => 'This user is already a member of the goal.']);
            }

            $membership ??= new GoalMembership;
            $membership->goal()->associate($goal);
            $membership->user()->associate($invitedUser);
            $membership->inviter()->associate($owner);
            $membership->status = GoalMembershipStatus::Pending;
            $membership->responded_at = null;
            $membership->save();

            return $membership;
        }, attempts: 3);
    }
}
