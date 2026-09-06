<?php

namespace App\Policies;

use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MonthlyIncomePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, MonthlyIncome $income): Response
    {
        return $user->id === $income->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, MonthlyIncome $income): Response
    {
        return $this->view($user, $income);
    }

    public function delete(User $user, MonthlyIncome $income): bool
    {
        return false;
    }
}
