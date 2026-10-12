<?php

namespace App\Application\Contracts;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Contracts\Ports\ContractInputValidator;
use App\Application\Contracts\Ports\ContractStore;
use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;

final class ChangeContractLifecycle
{
    public function __construct(
        private TransactionManager $transactions,
        private ContractAccess $access,
        private ContractInputValidator $inputValidator,
        private ContractStore $store,
    ) {}

    public function delete(ActorIdentity $actor, int $contractId, array $input): void
    {
        $this->change($actor, $contractId, $input, false);
    }

    public function restore(ActorIdentity $actor, int $contractId, array $input): void
    {
        $this->change($actor, $contractId, $input, true);
    }

    private function change(ActorIdentity $actor, int $contractId, array $input, bool $restore): void
    {
        $this->transactions->run(function () use ($actor, $contractId, $input, $restore) {
            $context = $this->access->lockForLifecycle($actor, $contractId, $restore);
            $data = $this->inputValidator->validateLifecycle($context, $input);
            if ((int) $data['version'] !== $context->contract->version()) {
                throw new ConcurrencyConflict('O contrato mudou. Recarregue antes de confirmar.');
            }
            $this->store->changeLifecycle($context, $restore, $data['reason']);
        });
    }
}
