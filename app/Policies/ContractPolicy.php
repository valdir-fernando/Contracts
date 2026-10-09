<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ! $user->trashed();
    }

    public function view(User $user, Contract $contract): bool
    {
        return ! $contract->trashed() && Contract::visibleTo($user)->whereKey($contract->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && ($user->isAdmin() || $user->scopes()->whereNotNull('fund_id')
            ->where(fn ($query) => $query->whereNull('department')->orWhere('department', '')->orWhereNotNull('department_id'))->exists());
    }

    public function update(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract);
    }
}
