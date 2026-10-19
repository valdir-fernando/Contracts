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
        return $this->canManage($actor, $user) && ! $user->trashed();
    }

    public function delete(User $actor, User $user): bool
    {
        return $this->canManage($actor, $user) && ! $user->trashed();
    }

    public function restore(User $actor, User $user): bool
    {
        return $this->canManage($actor, $user) && $user->trashed();
    }

    private function canManage(User $actor, User $user): bool
    {
        return $actor->isMaster() || ($actor->isAdmin() && $user->user_type === 'Usuario');
    }
}
