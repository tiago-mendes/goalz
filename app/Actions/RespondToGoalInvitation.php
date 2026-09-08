<?php

namespace App\Actions;

use App\GoalMembershipStatus;
use App\Models\GoalMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondToGoalInvitation
{
    public function handle(User $user, int $membershipId, GoalMembershipStatus $response): GoalMembership
    {
        if (! in_array($response, [GoalMembershipStatus::Accepted, GoalMembershipStatus::Declined], true)) {
            throw ValidationException::withMessages(['invitation' => 'Choose a valid invitation response.']);
        }

        return DB::transaction(function () use ($user, $membershipId, $response): GoalMembership {
            $membership = GoalMembership::query()
                ->whereKey($membershipId)
                ->whereBelongsTo($user)
                ->lockForUpdate()
                ->first();
            abort_if($membership === null, 404);

            if ($membership->status !== GoalMembershipStatus::Pending) {
                throw ValidationException::withMessages(['invitation' => 'This invitation has already been answered.']);
            }

            $membership->status = $response;
            $membership->responded_at = now();
            $membership->save();

            return $membership;
        }, attempts: 3);
    }
}
