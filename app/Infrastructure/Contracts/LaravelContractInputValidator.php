<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\Ports\ContractInputValidator;
use App\Application\Contracts\ValidatedContractInput;
use App\Application\Exceptions\InputRejected;
use App\Models\Contract;
use App\Models\ContractTypeRule;
use App\Services\Legacy\Normalizer;
use App\Support\ContractFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Contextual validation for HTTP and CLI; always called with locked current state. */
final class LaravelContractInputValidator implements ContractInputValidator
{
    public function validate(AuthorizedContract $context, array $input): ValidatedContractInput
    {
        $contract = ContractMapper::toModel($context->contract);
        $updating = $contract->exists;
        $rules = ['fund_id' => [$updating ? 'sometimes' : 'required', 'nullable', 'integer', 'exists:contract_funds,id'],
            'department_id' => 'sometimes|nullable|integer|exists:contract_departments,id',
            'version' => $updating ? 'required|integer|min:1' : 'prohibited',
            'administrative_status' => ['sometimes', Rule::in(array_keys(Contract::STATUSES))],
            'reason' => $updating ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000',
            'duplicate_confirmation' => 'sometimes|boolean', 'number_year' => 'prohibited', 'number_sequence' => 'prohibited'];
        $required = ['numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot', 'objeto',
            'data_contrato', 'vigencia_inicio', 'vigencia_fim_original', 'tipo', 'valor_total'];
        $type = $input['tipo'] ?? $contract->tipo;
        if (is_string($type) && (! $updating || $type !== $contract->tipo)) {
            $required = array_merge($required, ContractTypeRule::where('name_key', Normalizer::name($type))->value('required_fields') ?? []);
        }
        $writable = [];
        foreach (ContractFields::MAP as $definition) {
            $column = $definition['column'];
            if (in_array($column, ContractFields::GENERATED) && (! $updating || $contract->number_sequence !== null)) {
                // Old clients may still submit these controls; they never enter the writable patch.
                $rules[$column] = 'sometimes|nullable|string|max:15000';
                if ($updating && array_key_exists($column, $input) && $input[$column] !== $contract->$column) {
                    throw new InputRejected([$column => 'Este campo é gerado automaticamente e não pode ser alterado.']);
                }

                continue;
            }
            if (in_array($column, ContractFields::DERIVED)) {
                $rules[$column] = 'prohibited';

                continue;
            }
            $protected = $updating && ! $context->isAdministrator && (in_array($column, ContractFields::PERSONAL)
                || ($column === 'fornecedor_documento_snapshot' && strlen(Normalizer::document($contract->$column)) !== 14));
            if ($protected) {
                $rules[$column] = 'prohibited';

                continue;
            }
            $writable[] = $column;
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
        $labels = ['duplicate_confirmation' => 'confirmação de duplicidade'];
        foreach (ContractFields::MAP as $source => $definition) {
            $labels[$definition['column']] = ContractFields::label($source);
        }

        $data = $this->validated($input, $rules, $labels);
        $fields = [];
        if (isset($data['administrative_status'])) {
            $fields['administrative_status'] = $data['administrative_status'];
        }
        foreach (ContractFields::MAP as $definition) {
            $column = $definition['column'];
            if (array_key_exists($column, $data) && in_array($column, $writable, true)) {
                $fields[$column] = $definition['type'] === 'money' ? Normalizer::money($data[$column]) : $data[$column];
            }
        }

        return new ValidatedContractInput($data, $fields);
    }

    public function validateLifecycle(AuthorizedContract $context, array $input): array
    {
        return $this->validated($input, [
            'reason' => 'required|string|min:5|max:1000',
            'confirmation' => ['required', Rule::in([$context->contract->get('numero') ?: (string) $context->contract->id])],
            'version' => 'required|integer|min:1',
        ]);
    }

    private function validated(array $input, array $rules, array $labels = []): array
    {
        try {
            return Validator::make($input, $rules, [], $labels)->validate();
        } catch (ValidationException $error) {
            throw new InputRejected($error->errors());
        }
    }
}
