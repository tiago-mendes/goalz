<?php

namespace App\Policies;

use App\Models\BudgetRule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BudgetRulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, BudgetRule $rule): Response
    {
        return $user->id === $rule->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, BudgetRule $rule): Response
    {
        return $this->view($user, $rule);
    }
}
