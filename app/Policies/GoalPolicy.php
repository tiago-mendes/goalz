<?php

namespace App\Policies;

use App\GoalMembershipStatus;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GoalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Goal $goal): Response
    {
        if ($goal->isOwnedBy($user)) {
            return Response::allow();
        }

        return $goal->memberships()
            ->where('user_id', $user->id)
            ->where('status', GoalMembershipStatus::Accepted)
            ->exists()
                ? Response::allow()
                : Response::denyAsNotFound();
    }

    public function update(User $user, Goal $goal): Response
    {
        return $goal->isOwnedBy($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function invite(User $user, Goal $goal): Response
    {
        return $this->update($user, $goal);
    }

    public function removeMember(User $user, Goal $goal): Response
    {
        return $this->update($user, $goal);
    }

    public function leave(User $user, Goal $goal): Response
    {
        if ($goal->isOwnedBy($user)) {
            return Response::deny('The owner cannot leave their own goal.');
        }

        return $this->view($user, $goal);
    }

    public function allocate(User $user, Goal $goal): Response
    {
        return $this->view($user, $goal);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return false;
    }
}
