<?php

namespace App\Application\Contracts\Ports;

use App\Application\Contracts\AuthorizedContract;
use App\Domain\Contracts\ContractNumber;

interface ContractNumbering
{
    /** Allocate only inside the authorized save transaction; rollback restores the counter. */
    public function nextNumber(AuthorizedContract $context, int $fundId): ContractNumber;
}
