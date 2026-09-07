<?php

namespace App\Policies;

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
        return $user->id === $goal->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Goal $goal): Response
    {
        return $this->view($user, $goal);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return false;
    }
}
