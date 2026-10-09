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
}
