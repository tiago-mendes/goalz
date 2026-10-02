<?php

namespace App\Policies;

use App\Models\StatementImport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StatementImportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StatementImport $statementImport): Response
    {
        return $user->id === $statementImport->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StatementImport $statementImport): bool
    {
        return false;
    }

    public function delete(User $user, StatementImport $statementImport): bool
    {
        return false;
    }

    public function restore(User $user, StatementImport $statementImport): bool
    {
        return false;
    }

    public function forceDelete(User $user, StatementImport $statementImport): bool
    {
        return false;
    }
}
