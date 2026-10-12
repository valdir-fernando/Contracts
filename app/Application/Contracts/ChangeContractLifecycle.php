<?php

namespace App\Application\Contracts;

use App\Application\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ChangeContractLifecycle
{
    public function __construct(private LockActor $actors) {}

    public function delete(User $actor, Contract $target, array $input): void
    {
        $this->change($actor, $target, $input, false);
    }

    public function restore(User $actor, Contract $target, array $input): void
    {
        $this->change($actor, $target, $input, true);
    }

    private function change(User $actor, Contract $target, array $input, bool $restore = false): void
    {
        DB::transaction(function () use ($actor, $target, $input, $restore) {
            $actor = $this->actors->lock($actor);
            $contract = Contract::withTrashed()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($restore ? 'restore' : 'delete', $contract);
            $data = Validator::make($input, [
                'reason' => 'required|string|min:5|max:1000',
                'confirmation' => ['required', Rule::in([$contract->numero ?: (string) $contract->id])],
                'version' => 'required|integer|min:1',
            ])->validate();
            if ((int) $data['version'] !== $contract->version) {
                throw new ConflictHttpException('O contrato mudou. Recarregue antes de confirmar.');
            }
            $before = $contract->deleted_at?->toISOString();
            $restore ? $contract->restore() : $contract->delete();
            $contract->version++;
            $contract->updated_by = $actor->id;
            $contract->save();
            ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id,
                'action' => $restore ? 'restored' : 'deleted', 'reason' => $data['reason'],
                'changes' => ['deleted_at' => ['before' => $before, 'after' => $contract->deleted_at?->toISOString()]], 'created_at' => now()]);
        }, 3);
    }
}
