<?php

namespace App\Services;

use App\Domain\Contracts\ContractValidity;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractReview
{
    public function __construct(private LockActor $actors) {}

    public function confirm(User $actor, Contract $parent, array $input): void
    {
        DB::transaction(function () use ($actor, $parent, $input) {
            $actor = $this->actors->lock($actor);
            $contract = Contract::whereKey($parent->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $contract);
            abort_unless($actor->isAdmin(), 403);
            $rules = ['version' => 'required|integer|min:1', 'confirmation' => ['required', Rule::in([$contract->numero])],
                'acknowledged' => 'required|accepted', 'reason' => 'required|string|min:20|max:1000',
                'end_date' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:1000-01-01',
                'accumulated_amount' => ['sometimes', 'nullable', 'string', function ($attribute, $value, $fail) {
                    if (Normalizer::money($value) === null) {
                        $fail('Informe um valor com até quatro casas decimais.');
                    }
                }]];
            $data = Validator::make($input, $rules)->validate();
            if ((int) $data['version'] !== $contract->version) {
                throw new ConflictHttpException('O contrato ou seus registros mudaram. Recarregue para conferir novamente.');
            }
            abort_unless($contract->vigencia_em_revisao, 409, 'Este contrato não tem revisão de vigência pendente.');
            $pending = ContractHistoryRecord::where('kind', 'amendment')->whereNull('contract_id')->whereJsonContains('candidate_ids', $contract->id)->exists();
            if ($pending) {
                throw ValidationException::withMessages(['acknowledged' => 'Concilie os vínculos históricos candidatos a este contrato antes de concluir a revisão.']);
            }
            $before = $contract->only(['vigencia_fim_atual', 'valor_acumulado', 'vigencia_em_revisao']);
            if (array_key_exists('end_date', $data)) {
                $contract->vigencia_fim_atual = $data['end_date'];
            }
            if (ContractValidity::endsBeforeStart($contract->vigencia_inicio, $contract->end_date)) {
                throw ValidationException::withMessages(['end_date' => 'O término não pode ser anterior ao início do contrato.']);
            }
            if (array_key_exists('accumulated_amount', $data)) {
                $contract->valor_acumulado = Normalizer::money($data['accumulated_amount']);
            }
            $contract->vigencia_em_revisao = false;
            $contract->version++;
            $contract->updated_by = $actor->id;
            $contract->save();
            $changes = [];
            foreach ($contract->only(array_keys($before)) as $key => $value) {
                $changes[$key] = ['before' => $before[$key], 'after' => $value];
            }
            $changes['reviewed_amendment_ids'] = ['before' => null, 'after' => ContractAmendment::where('contract_id', $contract->id)->where('status', 'registrado')->pluck('id')->all()];
            $changes['reviewed_history_ids'] = ['before' => null, 'after' => ContractHistoryRecord::where('contract_id', $contract->id)->where('kind', 'amendment')->pluck('id')->all()];
            ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id, 'action' => 'review_confirmed',
                'reason' => $data['reason'], 'changes' => $changes, 'created_at' => now()]);
        }, 3);
    }
}
