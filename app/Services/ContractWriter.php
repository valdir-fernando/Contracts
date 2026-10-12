<?php

namespace App\Services;

use App\Application\Contracts\SaveContract;
use App\Models\Contract;
use App\Models\User;

/** Compatibility entry point for existing controllers, commands and integrations. */
class ContractWriter
{
    public function __construct(private SaveContract $saveContract) {}

    public function save(User $actor, array $input, ?Contract $target = null): Contract
    {
        return $this->saveContract->execute($actor, $input, $target);
    }
}
