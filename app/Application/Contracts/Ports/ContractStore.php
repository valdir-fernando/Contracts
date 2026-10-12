<?php

namespace App\Application\Contracts\Ports;

use App\Application\Contracts\AuthorizedContract;
use App\Domain\Contracts\ContractState;

interface ContractStore
{
    /** Serialize native creations by fund and use a current, scoped duplicate read. */
    public function hasDuplicate(AuthorizedContract $context, ContractState $contract): bool;

    /** Persistence and audit are inseparable and share the surrounding transaction. */
    public function save(AuthorizedContract $context, ContractState $contract, ?string $reason, bool $duplicateConfirmed): ContractState;

    public function changeLifecycle(AuthorizedContract $context, bool $restore, string $reason): void;
}
