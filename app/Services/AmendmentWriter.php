<?php

namespace App\Services;

use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Support\AmendmentFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AmendmentWriter
{
    public function __construct(private LockActor $actors) {}

    public static function seed(Contract $contract): array
    {
        $payload = [];
        foreach (AmendmentFields::MAP as $field => $definition) {
            $payload[$field] = isset($definition['column']) ? $contract->{$definition['column']} : null;
        }

        return $payload;
    }

    public function save(User $actor, Contract $parent, array $input, ?int $id = null): ContractAmendment
    {
        return DB::transaction(function () use ($actor, $parent, $input, $id) {
            [$actor, $contract] = $this->lock($actor, $parent);
            $amendment = $id ? ContractAmendment::where('contract_id', $contract->id)->lockForUpdate()->findOrFail($id) : new ContractAmendment;
            abort_if(! $actor->isAdmin() && $amendment->status === 'registrado', 403);
            $payload = $id ? $amendment->payload : self::seed($contract);
            $rules = ['fields' => [$id ? 'sometimes' : 'required', 'array:'.implode(',', array_keys(AmendmentFields::MAP))],
                'contract_version' => 'required|integer|min:1', 'version' => $id ? 'required|integer|min:1' : 'prohibited',
                'reason' => 'required|string|min:5|max:1000', 'attachment_id' => 'sometimes|nullable|integer|min:1',
                'status' => ['sometimes', Rule::in(array_keys(ContractAmendment::STATUSES))]];
            foreach (AmendmentFields::MAP as $field => $definition) {
                $key = 'fields.'.$field;
                if (in_array($field, AmendmentFields::DERIVED) || ! $actor->isAdmin() && in_array($field, AmendmentFields::PERSONAL)) {
                    $rules[$key] = 'prohibited';

                    continue;
                }
                $required = in_array($field, AmendmentFields::REQUIRED);
                $rules[$key] = ['bail', $id ? 'sometimes' : ($required ? 'required' : 'sometimes'), $required ? 'required' : 'nullable', 'string', 'max:'.($field === 'N_ADT' ? 64 : 15000)];
                if ($definition['type'] === 'date') {
                    $rules[$key][] = 'date_format:Y-m-d';
                    $rules[$key][] = 'after_or_equal:1000-01-01';
                }
                if ($definition['type'] === 'money') {
                    $rules[$key][] = function ($attribute, $value, $fail) {
                        if (Normalizer::money($value) === null) {
                            $fail('Informe um valor válido com até quatro casas decimais.');
                        }
                    };
                }
                if (in_array($field, ['CPF_Socio', 'CPF_Gestor'])) {
                    $rules[$key][] = function ($attribute, $value, $fail) use ($payload, $field) {
                        $digits = Normalizer::document($value);
                        if ($digits !== Normalizer::document($payload[$field] ?? null) && (strlen($digits) !== 11 || ! Normalizer::validDocument($digits))) {
                            $fail('Informe um CPF com dígitos verificadores válidos.');
                        }
                    };
                }
            }
            $labels = [];
            foreach (AmendmentFields::MAP as $field => $definition) {
                $labels['fields.'.$field] = $definition['label'];
            }
            $data = Validator::make($input, $rules, [], $labels)->validate();
            $this->versions($contract, $amendment, $data, (bool) $id);
            $status = $data['status'] ?? ($id ? $amendment->status : 'rascunho');
            abort_if(! $actor->isAdmin() && $status !== 'rascunho', 403);
            foreach ($data['fields'] ?? [] as $field => $value) {
                if (in_array($field, AmendmentFields::DERIVED, true) || ! $actor->isAdmin() && in_array($field, AmendmentFields::PERSONAL, true)) {
                    continue;
                }
                $payload[$field] = AmendmentFields::MAP[$field]['type'] === 'money' ? Normalizer::money($value) : $value;
            }
            $payload['N_ADT'] = trim($payload['N_ADT']);
            if ($payload['N_ADT'] === '') {
                throw ValidationException::withMessages(['fields.N_ADT' => 'Informe o número do aditivo.']);
            }
            foreach (['Incio_Prorrog' => 'Final_Prorrog', 'Incio_Servico' => 'Final_Servico'] as $start => $end) {
                if (! empty($payload[$start]) && ! empty($payload[$end]) && $payload[$end] < $payload[$start]) {
                    throw ValidationException::withMessages(['fields.'.$end => 'O término não pode ser anterior ao início do período.']);
                }
            }
            $duplicate = ContractAmendment::withTrashed()->where('contract_id', $contract->id)->where('number', trim($payload['N_ADT']));
            if ($id) {
                $duplicate->where('id', '!=', $id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['fields.N_ADT' => 'Este número já está reservado neste contrato, inclusive entre os aditivos excluídos.']);
            }
            $attachmentId = array_key_exists('attachment_id', $data) ? $data['attachment_id'] : $amendment->attachment_id;
            if ($attachmentId && (! $id || (int) $attachmentId !== (int) $amendment->attachment_id)
                && ! ContractAttachment::where('contract_id', $contract->id)->whereKey($attachmentId)->exists()) {
                throw ValidationException::withMessages(['attachment_id' => 'Selecione um documento ativo deste contrato.']);
            }
            $changes = [];
            foreach ($payload as $field => $value) {
                if (! $id || ($amendment->payload[$field] ?? null) !== $value) {
                    $changes['aditivo.'.$field] = ['before' => $id ? ($amendment->payload[$field] ?? null) : null, 'after' => $value];
                }
            }
            foreach (['status' => $status, 'attachment_id' => $attachmentId] as $field => $value) {
                if (! $id || $value != $amendment->$field) {
                    $changes['aditivo.'.$field] = ['before' => $id ? $amendment->$field : null, 'after' => $value];
                }
            }
            $wasRegistered = $amendment->status === 'registrado';
            $amendment->fill(['contract_id' => $contract->id, 'number' => $payload['N_ADT'], 'event_date' => $payload['Data_ADT'],
                'amount' => $payload['VL_ADT'] ?? null, 'payload' => $payload, 'status' => $status,
                'attachment_id' => $attachmentId, 'created_by' => $id ? $amendment->created_by : $actor->id,
                'updated_by' => $actor->id, 'version' => $id ? $amendment->version + 1 : 1]);
            $amendment->save();
            $this->audit($actor, $contract, $amendment, $id ? 'amendment_updated' : 'amendment_created', $data['reason'], $changes, $wasRegistered || $status === 'registrado');

            return $amendment;
        }, 3);
    }

    public function change(User $actor, Contract $parent, int $id, array $input, bool $restore): void
    {
        DB::transaction(function () use ($actor, $parent, $id, $input, $restore) {
            [$actor, $contract] = $this->lock($actor, $parent);
            Gate::forUser($actor)->authorize('delete', $contract);
            $query = ContractAmendment::where('contract_id', $contract->id)->lockForUpdate();
            $amendment = ($restore ? $query->onlyTrashed() : $query)->findOrFail($id);
            $data = Validator::make($input, ['contract_version' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
                'reason' => 'required|string|min:5|max:1000', 'confirmation' => ['required', Rule::in([$amendment->number])]])->validate();
            $this->versions($contract, $amendment, $data, true);
            $before = $amendment->deleted_at?->toISOString();
            $restore ? $amendment->restore() : $amendment->delete();
            $amendment->version++;
            $amendment->updated_by = $actor->id;
            $amendment->save();
            $this->audit($actor, $contract, $amendment, $restore ? 'amendment_restored' : 'amendment_deleted', $data['reason'],
                ['aditivo.deleted_at' => ['before' => $before, 'after' => $amendment->deleted_at?->toISOString()]], $amendment->status === 'registrado');
        }, 3);
    }

    private function lock(User $actor, Contract $parent): array
    {
        $actor = $this->actors->lock($actor);
        $contract = Contract::whereKey($parent->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $contract);

        return [$actor, $contract];
    }

    private function versions(Contract $contract, ContractAmendment $amendment, array $data, bool $exists): void
    {
        if ((int) $data['contract_version'] !== $contract->version || $exists && (int) $data['version'] !== $amendment->version) {
            throw new ConflictHttpException('O contrato ou o aditivo mudou. Recarregue os dados antes de confirmar.');
        }
    }

    private function audit(User $actor, Contract $contract, ContractAmendment $amendment, string $action, string $reason, array $changes, bool $review): void
    {
        $changes['aditivo.ID'] = ['before' => null, 'after' => $amendment->id];
        if ($review && ! $contract->vigencia_em_revisao) {
            $changes['vigencia_em_revisao'] = ['before' => false, 'after' => true];
            $contract->vigencia_em_revisao = true;
        }
        $contract->version++;
        $contract->updated_by = $actor->id;
        $contract->save();
        ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id, 'action' => $action,
            'reason' => $reason, 'changes' => $changes, 'created_at' => now()]);
    }
}
