<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\Ports\ContractStore;
use App\Domain\Contracts\ContractState;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentContractStore implements ContractStore
{
    public function hasDuplicate(AuthorizedContract $context, ContractState $contract): bool
    {
        TransactionGuard::requireActive();
        DB::table('contract_funds')->where('id', $contract->get('fund_id'))->lockForUpdate()->firstOrFail();
        $actor = User::findOrFail($context->actor->id);

        return Contract::visibleTo($actor)->where('numero', $contract->get('numero'))
            ->where('fund_id', $contract->get('fund_id'))->where('exercicio', $contract->get('exercicio'))
            ->where('processo', $contract->get('processo'))->select('id')->lockForUpdate()->first() !== null;
    }

    public function save(AuthorizedContract $context, ContractState $contract, ?string $reason, bool $duplicateConfirmed): ContractState
    {
        TransactionGuard::requireActive();
        if ($contract->id !== $context->contract->id) {
            throw new LogicException('O contrato gravado deve corresponder ao contrato autorizado.');
        }
        $model = ContractMapper::toModel($contract);
        $before = $contract->original();
        $changes = [];
        foreach ($model->getDirty() as $column => $value) {
            if (! in_array($column, ['updated_by', 'created_by', 'version'])) {
                $changes[$column] = ['before' => $before[$column] ?? null, 'after' => $value];
            }
        }
        $model->save();
        if ($duplicateConfirmed) {
            $changes['duplicate_confirmation'] = ['before' => null, 'after' => true];
        }
        ContractAudit::create(['contract_id' => $model->id, 'actor_id' => $context->actor->id,
            'action' => $contract->id === null ? 'created' : 'updated', 'changes' => $changes, 'reason' => $reason, 'created_at' => now()]);

        return ContractMapper::toDomain($model);
    }

    public function changeLifecycle(AuthorizedContract $context, bool $restore, string $reason): void
    {
        TransactionGuard::requireActive();
        $model = ContractMapper::toModel($context->contract);
        $before = $model->deleted_at?->toISOString();
        $restore ? $model->restore() : $model->delete();
        $model->version++;
        $model->updated_by = $context->actor->id;
        $model->save();
        ContractAudit::create(['contract_id' => $model->id, 'actor_id' => $context->actor->id,
            'action' => $restore ? 'restored' : 'deleted', 'reason' => $reason,
            'changes' => ['deleted_at' => ['before' => $before, 'after' => $model->deleted_at?->toISOString()]], 'created_at' => now()]);
    }
}
