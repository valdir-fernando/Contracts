<?php

namespace App\Application\Contracts;

use App\Models\Contract;
use App\Models\ContractTypeRule;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Support\ContractFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Contextual validation for HTTP and CLI; always called with locked current state. */
class ContractInputValidator
{
    public function validate(User $actor, Contract $contract, array $input): array
    {
        $updating = $contract->exists;
        $rules = ['fund_id' => [$updating ? 'sometimes' : 'required', 'nullable', 'integer', 'exists:contract_funds,id'],
            'department_id' => 'sometimes|nullable|integer|exists:contract_departments,id',
            'version' => $updating ? 'required|integer|min:1' : 'prohibited',
            'administrative_status' => ['sometimes', Rule::in(array_keys(Contract::STATUSES))],
            'reason' => $updating ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000'];
        $required = ['numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot', 'objeto',
            'data_contrato', 'vigencia_inicio', 'vigencia_fim_original', 'tipo', 'valor_total'];
        $type = $input['tipo'] ?? $contract->tipo;
        if (is_string($type) && (! $updating || $type !== $contract->tipo)) {
            $required = array_merge($required, ContractTypeRule::where('name_key', Normalizer::name($type))->value('required_fields') ?? []);
        }
        foreach (ContractFields::MAP as $definition) {
            $column = $definition['column'];
            if (in_array($column, ContractFields::DERIVED)) {
                $rules[$column] = 'prohibited';

                continue;
            }
            $protected = $updating && ! $actor->isAdmin() && (in_array($column, ContractFields::PERSONAL)
                || ($column === 'fornecedor_documento_snapshot' && strlen(Normalizer::document($contract->$column)) !== 14));
            if ($protected) {
                $rules[$column] = 'prohibited';

                continue;
            }
            $typeRequired = in_array($column, $required) && array_key_exists($column, ContractTypeRule::FIELDS);
            $mandatory = $typeRequired || in_array($column, $required) && (! $updating || $contract->getRawOriginal($column) !== null && $contract->getRawOriginal($column) !== '');
            if ($typeRequired && $updating && ! array_key_exists($column, $input)) {
                $input[$column] = $contract->$column;
            }
            $rules[$column] = ['bail', $updating ? 'sometimes' : ($mandatory ? 'required' : 'sometimes'), $mandatory ? 'required' : 'nullable', 'string', 'max:15000'];
            if ($definition['type'] === 'date') {
                $rules[$column][] = 'date_format:Y-m-d';
                $rules[$column][] = 'after_or_equal:1000-01-01';
            }
            if ($definition['type'] === 'money') {
                $rules[$column][] = function ($attribute, $value, $fail) {
                    if (Normalizer::money($value) === null) {
                        $fail('Informe um valor monetário válido, com até quatro casas decimais.');
                    }
                };
            }
            if ($column === 'fornecedor_documento_snapshot') {
                $rules[$column][] = function ($attribute, $value, $fail) use ($updating, $contract) {
                    if ((! $updating || $value !== $contract->fornecedor_documento_snapshot) && ! Normalizer::validDocument(Normalizer::document($value))) {
                        $fail('Informe um CPF ou CNPJ com dígitos verificadores válidos.');
                    }
                };
            }
            if (in_array($column, ['cpf_socio', 'cpf_gestor', 'cpf_fiscal'])) {
                $rules[$column][] = function ($attribute, $value, $fail) use ($updating, $contract, $column) {
                    $digits = Normalizer::document($value);
                    if ($updating && $digits === Normalizer::document($contract->$column)) {
                        return;
                    }
                    if (strlen($digits) !== 11 || ! Normalizer::validDocument($digits)) {
                        $fail('Informe um CPF com dígitos verificadores válidos.');
                    }
                };
            }
        }
        $labels = [];
        foreach (ContractFields::MAP as $source => $definition) {
            $labels[$definition['column']] = ContractFields::label($source);
        }

        return Validator::make($input, $rules, [], $labels)->validate();
    }
}
