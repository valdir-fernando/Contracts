<?php

namespace App\Application\Contracts;

use App\Application\Security\LockActor;
use App\Domain\Contracts\ContractValidity;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Support\ContractFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SaveContract
{
    public function __construct(private LockActor $actors, private ContractInputValidator $inputValidator) {}

    public function execute(User $actor, array $input, ?Contract $target = null): Contract
    {
        return DB::transaction(function () use ($actor, $input, $target) {
            $actor = $this->actors->lock($actor);
            $contract = $target ? Contract::whereKey($target->id)->lockForUpdate()->firstOrFail() : new Contract;
            Gate::forUser($actor)->authorize($target ? 'update' : 'create', $target ? $contract : Contract::class);
            $data = $this->inputValidator->validate($actor, $contract, $input);
            if ($target && (int) $data['version'] !== $contract->version) {
                throw new ConflictHttpException('O contrato foi alterado por outra pessoa. Recarregue os dados antes de salvar.');
            }
            $before = $contract->getAttributes();
            if (isset($data['administrative_status'])) {
                $contract->administrative_status = $data['administrative_status'];
            }
            foreach (ContractFields::MAP as $definition) {
                $column = $definition['column'];
                if (! array_key_exists($column, $data) || in_array($column, ContractFields::DERIVED)) {
                    continue;
                }
                $contract->$column = $definition['type'] === 'money' ? Normalizer::money($data[$column]) : $data[$column];
            }
            $fundId = $data['fund_id'] ?? $contract->fund_id;
            $departmentId = array_key_exists('department_id', $data) ? $data['department_id'] : $contract->department_id;
            $scopeAllowed = $actor->isAdmin() || $actor->scopes()->where('fund_id', $fundId)
                ->where(function ($query) use ($departmentId) {
                    $query->whereNull('department')->orWhere('department', '');
                    if ($departmentId) {
                        $query->orWhere('department_id', $departmentId);
                    }
                })->exists();
            abort_unless($scopeAllowed, 403);
            // Preserve unresolved legacy ownership unless explicitly reassigned.
            $ownershipChanged = array_key_exists('fund_id', $data) && (int) $fundId !== (int) $contract->fund_id
                || array_key_exists('department_id', $data) && (int) $departmentId !== (int) $contract->department_id;
            if (! $target || $ownershipChanged || (! $contract->ownership_resolved && ! empty($data['fund_id']))) {
                if (! $fundId) {
                    throw ValidationException::withMessages(['fund_id' => 'Selecione um fundo.']);
                }
                $fund = DB::table('contract_funds')->find($fundId);
                $department = $departmentId ? DB::table('contract_departments')->find($departmentId) : null;
                $contract->fill(['fund_id' => $fund->id, 'department_id' => $department?->id, 'fundo_nome_snapshot' => $fund->name,
                    'fundo_documento_snapshot' => $fund->document, 'secretaria_nome_snapshot' => $department?->name, 'ownership_resolved' => true]);
            }
            $changedDates = ! $target;
            foreach (['vigencia_inicio', 'vigencia_fim_original', 'vigencia_fim_atual'] as $column) {
                $changedDates = $changedDates || substr($before[$column] ?? '', 0, 10) !== substr($contract->getAttributes()[$column] ?? '', 0, 10);
            }
            if ($changedDates && $contract->vigencia_inicio) {
                foreach (['vigencia_fim_original', 'vigencia_fim_atual'] as $column) {
                    if (ContractValidity::endsBeforeStart($contract->vigencia_inicio, $contract->$column)) {
                        throw ValidationException::withMessages([$column => 'O término não pode ser anterior ao início da vigência.']);
                    }
                }
            }
            $document = Normalizer::document($contract->fornecedor_documento_snapshot);
            $suppliers = Normalizer::validDocument($document) ? DB::table('suppliers')->where('document_key', $document)->pluck('id') : collect();
            $contract->supplier_document_key = strlen($document) <= 32 ? ($document ?: null) : null;
            $contract->supplier_id = $suppliers->count() === 1 ? $suppliers->first() : null;
            if (preg_match('~/([0-9]{2}|[0-9]{4})$~D', trim($contract->numero ?? ''), $match)) {
                $contract->exercicio = strlen($match[1]) === 2 ? 2000 + (int) $match[1] : (int) $match[1];
            } else {
                $contract->exercicio = $contract->data_contrato?->year;
            }
            $hasAmendments = collect([$contract->n_aditivo, $contract->proc_aditivo])->contains(fn ($value) => trim($value ?? '') !== '' && trim($value) !== '0');
            $contract->vigencia_em_revisao = $hasAmendments || ($target && $contract->vigencia_em_revisao);
            $contract->quality_issues ??= [];
            $contract->created_by ??= $target ? null : $actor->id;
            $contract->updated_by = $actor->id;
            $contract->version = $target ? $contract->version + 1 : 1;
            $changes = [];
            foreach ($contract->getDirty() as $column => $value) {
                if (! in_array($column, ['updated_by', 'created_by', 'version'])) {
                    $changes[$column] = ['before' => $before[$column] ?? null, 'after' => $value];
                }
            }
            $contract->save();
            ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id,
                'action' => $target ? 'updated' : 'created', 'changes' => $changes, 'reason' => $data['reason'] ?? null, 'created_at' => now()]);

            return $contract;
        }, 3);
    }
}
