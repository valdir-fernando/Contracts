<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractFinancialRecord;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Support\FinancialFields;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialRecordWriter
{
    public function save(User $actor, Contract $parent, string $kind, array $input, ?int $id = null): ContractFinancialRecord
    {
        abort_unless(isset(FinancialFields::KINDS[$kind]), 404);

        return DB::transaction(function () use ($actor, $parent, $kind, $input, $id) {
            [$actor, $contract] = $this->lock($actor, $parent);
            $record = $id ? ContractFinancialRecord::where('contract_id', $contract->id)->where('kind', $kind)->lockForUpdate()->findOrFail($id) : new ContractFinancialRecord;
            abort_if(! $actor->isAdmin() && $record->status === 'registrado', 403);
            $rules = ['fields' => [$id ? 'sometimes' : 'required', 'array:'.implode(',', array_keys(FinancialFields::MAP[$kind]))],
                'number' => [$id ? 'sometimes' : 'required', 'required', 'string', 'max:64', 'regex:/\S/u'],
                'year' => [$id ? 'sometimes' : 'required', 'required', 'integer', 'between:1000,9999'],
                'event_date' => [$id ? 'sometimes' : 'required', 'required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
                'status' => ['sometimes', Rule::in(array_keys(ContractFinancialRecord::STATUSES))],
                'budget_id' => $kind === 'dotacao' ? 'prohibited' : 'sometimes|nullable|integer|min:1',
                'amendment_id' => 'sometimes|nullable|integer|min:1',
                'version' => $id ? 'required|integer|min:1' : 'prohibited', 'contract_version' => 'required|integer|min:1',
                'reason' => 'required|string|min:5|max:1000'];
            foreach (FinancialFields::MAP[$kind] as $field => $definition) {
                if (str_starts_with($definition[1], 'readonly')) {
                    $rules['fields.'.$field] = 'prohibited';

                    continue;
                }
                $required = in_array($field, FinancialFields::REQUIRED[$kind]);
                $rules['fields.'.$field] = ['bail', $id ? 'sometimes' : ($required ? 'required' : 'sometimes'), $required ? 'required' : 'nullable', 'string', 'max:15000'];
                if ($definition[1] === 'money') {
                    $rules['fields.'.$field][] = function ($attribute, $value, $fail) {
                        $money = Normalizer::money($value);
                        if ($money === null || BigDecimal::of($money)->isNegative()) {
                            $fail('Informe um valor não negativo com até quatro casas decimais.');
                        }
                    };
                }
            }
            $data = Validator::make($input, $rules)->validate();
            $this->versions($contract, $record, $data, (bool) $id);
            $status = $data['status'] ?? ($id ? $record->status : 'rascunho');
            abort_if(! $actor->isAdmin() && $status !== 'rascunho', 403);
            $number = trim($data['number'] ?? $record->number);
            $year = $data['year'] ?? $record->year;
            $duplicate = ContractFinancialRecord::withTrashed()->where('contract_id', $contract->id)->where('kind', $kind)->where('year', $year)->where('number', $number);
            if ($id) {
                $duplicate->where('id', '!=', $id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['number' => 'Número reservado neste contrato, tipo e exercício, inclusive entre excluídos.']);
            }
            $payload = $id ? $record->payload : FinancialFields::seed($kind, $contract);
            foreach ($data['fields'] ?? [] as $field => $value) {
                $payload[$field] = FinancialFields::MAP[$kind][$field][1] === 'money' ? Normalizer::money($value) : $value;
            }
            $links = [];
            foreach (['budget_id', 'amendment_id'] as $field) {
                $links[$field] = array_key_exists($field, $data) ? $data[$field] : $record->$field;
                if (! $links[$field] || $id && (int) $links[$field] === (int) $record->$field) {
                    continue;
                }
                $query = $field === 'budget_id' ? ContractFinancialRecord::where('kind', 'dotacao') : ContractAmendment::query();
                if (! $query->where('contract_id', $contract->id)->whereKey($links[$field])->exists()) {
                    throw ValidationException::withMessages([$field => 'Selecione um registro ativo deste contrato.']);
                }
            }
            $before = $id ? $record->only(['number', 'year', 'event_date', 'amount', 'status', 'payload', 'budget_id', 'amendment_id']) : null;
            $record->fill(array_merge($links, ['contract_id' => $contract->id, 'kind' => $kind, 'number' => $number, 'year' => $year,
                'event_date' => $data['event_date'] ?? $record->event_date, 'amount' => $payload['Valor'] ?? null, 'payload' => $payload,
                'status' => $status, 'created_by' => $id ? $record->created_by : $actor->id, 'updated_by' => $actor->id,
                'version' => $id ? $record->version + 1 : 1]));
            $record->save();
            $this->audit($actor, $contract, $record, $id ? 'financial_updated' : 'financial_created', $data['reason'], $before,
                $record->only(['number', 'year', 'event_date', 'amount', 'status', 'payload', 'budget_id', 'amendment_id']));

            return $record;
        }, 3);
    }

    public function change(User $actor, Contract $parent, int $id, array $input, bool $restore): void
    {
        DB::transaction(function () use ($actor, $parent, $id, $input, $restore) {
            [$actor, $contract] = $this->lock($actor, $parent);
            Gate::forUser($actor)->authorize('delete', $contract);
            $query = ContractFinancialRecord::where('contract_id', $contract->id)->lockForUpdate();
            $record = ($restore ? $query->onlyTrashed() : $query)->findOrFail($id);
            $data = Validator::make($input, ['version' => 'required|integer|min:1', 'contract_version' => 'required|integer|min:1',
                'reason' => 'required|string|min:5|max:1000', 'confirmation' => ['required', Rule::in([$record->number])]])->validate();
            $this->versions($contract, $record, $data, true);
            $before = $record->deleted_at?->toISOString();
            $restore ? $record->restore() : $record->delete();
            $record->version++;
            $record->updated_by = $actor->id;
            $record->save();
            $this->audit($actor, $contract, $record, $restore ? 'financial_restored' : 'financial_deleted', $data['reason'], $before, $record->deleted_at?->toISOString());
        }, 3);
    }

    private function lock(User $actor, Contract $parent): array
    {
        $sessionVersion = $actor->session_version;
        $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $contract = Contract::whereKey($parent->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $contract);
        abort_unless($actor->session_version === $sessionVersion, 403);

        return [$actor, $contract];
    }

    private function versions(Contract $contract, ContractFinancialRecord $record, array $input, bool $exists): void
    {
        if ((int) $input['contract_version'] !== $contract->version || $exists && (int) $input['version'] !== $record->version) {
            throw new ConflictHttpException('O contrato ou o registro mudou. Recarregue antes de confirmar.');
        }
    }

    private function audit(User $actor, Contract $contract, ContractFinancialRecord $record, string $action, string $reason, mixed $before, mixed $after): void
    {
        $contract->version++;
        $contract->updated_by = $actor->id;
        $contract->save();
        ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id, 'action' => $action, 'reason' => $reason, 'created_at' => now(),
            'changes' => ['financeiro.ID' => ['before' => null, 'after' => $record->id],
                'financeiro.'.$record->kind => ['before' => $before, 'after' => $after]]]);
    }
}
