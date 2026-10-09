<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin() && ! $user->trashed();
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->isAdmin() && ! $user->trashed();
    }

    public function restore(User $actor, User $user): bool
    {
        return $actor->isAdmin() && $user->trashed();
    }
}
