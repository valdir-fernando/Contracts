<?php

namespace App\Application\Contracts;

use App\Application\Security\ActorIdentity;
use App\Domain\Contracts\ContractState;

/** Current state returned only after transactional actor/contract authorization. */
final readonly class AuthorizedContract
{
    public function __construct(public ActorIdentity $actor, public bool $isAdministrator, public ContractState $contract) {}
}
