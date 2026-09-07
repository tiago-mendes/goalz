<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Account $account): Response
    {
        return $user->id === $account->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Account $account): Response
    {
        return $this->view($user, $account);
    }

    public function delete(User $user, Account $account): bool
    {
        return false;
    }
}
