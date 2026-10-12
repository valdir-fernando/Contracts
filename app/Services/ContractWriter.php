<?php

namespace App\Services;

use App\Application\Contracts\SaveContract;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Contracts\ContractMapper;
use App\Infrastructure\Http\ApplicationErrors;
use App\Models\Contract;
use App\Models\User;

/** Compatibility entry point for existing controllers, commands and integrations. */
class ContractWriter
{
    public function __construct(private SaveContract $saveContract) {}

    public function save(User $actor, array $input, ?Contract $target = null): Contract
    {
        $saved = ApplicationErrors::translate(fn () => $this->saveContract->execute(new ActorIdentity($actor->id, $actor->session_version), $input, $target?->id));

        return ContractMapper::toModel($saved, $target === null);
    }
}
