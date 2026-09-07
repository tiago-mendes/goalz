<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expense $expense): Response
    {
        return $user->id === $expense->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Expense $expense): Response
    {
        return $this->view($user, $expense);
    }

    public function delete(User $user, Expense $expense): Response
    {
        return $this->view($user, $expense);
    }

    public function restore(User $user, Expense $expense): Response
    {
        return $this->view($user, $expense);
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
