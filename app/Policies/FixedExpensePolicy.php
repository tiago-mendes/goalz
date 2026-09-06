<?php

namespace App\Policies;

use App\Models\FixedExpense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FixedExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, FixedExpense $expense): Response
    {
        return $user->id === $expense->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, FixedExpense $expense): Response
    {
        return $this->view($user, $expense);
    }

    public function delete(User $user, FixedExpense $expense): bool
    {
        return false;
    }
}
