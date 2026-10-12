<?php

namespace App\Application\Contracts\Ports;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Security\ActorIdentity;

interface ContractAccess
{
    /** Must run inside a transaction; actor lock precedes contract lock and policy. */
    public function lockForSave(ActorIdentity $actor, ?int $contractId): AuthorizedContract;

    public function lockForLifecycle(ActorIdentity $actor, int $contractId, bool $restore): AuthorizedContract;

    public function canAssign(AuthorizedContract $context, ?int $fundId, ?int $departmentId): bool;
}
