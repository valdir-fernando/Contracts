<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractServiceOrder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Laravel adapter for documentary orders; no financial or validity effects. */
final class RegisterServiceOrder
{
    public function __construct(private TransactionManager $transactions, private ContractAccess $access) {}

    public function save(ActorIdentity $actor, int $contractId, array $input): array
    {
        return $this->transactions->run(function () use ($actor, $contractId, $input) {
            $context = $this->access->lockForSave($actor, $contractId);
            TransactionGuard::requireActive();
            $data = Validator::make($input, [
                'contract_version' => 'required|integer|min:1',
                'amendment_id' => 'nullable|integer|min:1',
                'number' => 'required|string|max:64',
                'issued_on' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01',
                'deadline' => 'required|string|max:255',
                'notes' => 'nullable|string|max:2000',
            ])->validate();
            if ((int) $data['contract_version'] !== $context->contract->version()) {
                throw new ConflictHttpException('O contrato mudou. Recarregue e confira os dados antes de cadastrar a ordem.');
            }
            $amendmentId = $data['amendment_id'] ?? null;
            if ($amendmentId && ! ContractAmendment::where('contract_id', $contractId)->whereKey($amendmentId)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['amendment_id' => 'Selecione um aditivo ativo deste contrato.']);
            }
            $order = ContractServiceOrder::create([
                'contract_id' => $contractId, 'amendment_id' => $amendmentId,
                'number' => trim($data['number']), 'issued_on' => $data['issued_on'],
                'deadline' => trim($data['deadline']), 'notes' => $data['notes'] ?? null,
                'created_by' => $context->actor->id,
            ]);
            $contract = Contract::findOrFail($contractId);
            $contract->version = $context->contract->version() + 1;
            $contract->updated_by = $context->actor->id;
            $contract->save();
            $changes = [];
            foreach (['id', 'amendment_id', 'number', 'issued_on', 'deadline', 'notes'] as $field) {
                $changes['ordem_servico.'.$field] = ['before' => null, 'after' => $field === 'issued_on' ? $data['issued_on'] : $order->$field];
            }
            ContractAudit::create([
                'contract_id' => $contractId, 'actor_id' => $context->actor->id,
                'action' => 'service_order_created', 'reason' => 'Cadastro de ordem de serviço',
                'changes' => $changes, 'created_at' => now(),
            ]);

            return ['id' => $order->id, 'contract_version' => $contract->version];
        });
    }
}
