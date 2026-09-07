<?php

namespace App\Policies;

use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CreditCardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, CreditCard $creditCard): Response
    {
        return $user->id === $creditCard->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, CreditCard $creditCard): Response
    {
        return $this->view($user, $creditCard);
    }

    public function delete(User $user, CreditCard $creditCard): bool
    {
        return false;
    }
}
