<?php

namespace App\Services;

use App\Application\Contracts\ChangeContractLifecycle;
use App\Models\Contract;
use App\Models\User;

/** Retains the existing signature while exposing explicit application use cases. */
class ContractLifecycle
{
    public function __construct(private ChangeContractLifecycle $lifecycle) {}

    public function change(User $actor, Contract $target, array $input, bool $restore = false): void
    {
        if ($restore) {
            $this->lifecycle->restore($actor, $target, $input);
        } else {
            $this->lifecycle->delete($actor, $target, $input);
        }
    }
}
