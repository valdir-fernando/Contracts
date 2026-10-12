<?php

namespace App\Services;

use App\Application\Contracts\ChangeContractLifecycle;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Http\ApplicationErrors;
use App\Models\Contract;
use App\Models\User;

/** Retains the existing signature while exposing explicit application use cases. */
class ContractLifecycle
{
    public function __construct(private ChangeContractLifecycle $lifecycle) {}

    public function change(User $actor, Contract $target, array $input, bool $restore = false): void
    {
        $identity = new ActorIdentity($actor->id, $actor->session_version);
        ApplicationErrors::translate(function () use ($identity, $target, $input, $restore) {
            if ($restore) {
                $this->lifecycle->restore($identity, $target->id, $input);
            } else {
                $this->lifecycle->delete($identity, $target->id, $input);
            }
        });
    }
}
