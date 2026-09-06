<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExpenseCategory $category): Response
    {
        return $user->id === $category->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, ExpenseCategory $category): Response
    {
        return $this->view($user, $category);
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return false;
    }
}
