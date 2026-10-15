<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\Ports\ContractInputValidator;
use App\Application\Contracts\ValidatedContractInput;
use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\InputRejected;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\SelectedFund;
use App\Models\Contract;
use App\Models\ContractTypeRule;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Services\SupplierCatalog;
use App\Support\ContractFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Contextual validation for HTTP and CLI; always called with locked current state. */
final class LaravelContractInputValidator implements ContractInputValidator
{
    public function __construct(private Request $request, private SelectedFund $selectedFund, private InspectorCatalog $inspectors) {}

    public function validate(AuthorizedContract $context, array $input): ValidatedContractInput
    {
        $contract = ContractMapper::toModel($context->contract);
        $updating = $contract->exists;
        if (! $updating && $this->request->routeIs('contracts.store')) {
            TransactionGuard::requireActive();
            $actor = User::findOrFail($context->actor->id);
            $sessionFundId = $this->request->session()->get('fund_id');
            $fund = is_numeric($sessionFundId) && $this->selectedFund->allows($actor, (int) $sessionFundId)
                ? DB::table('contract_funds')->where('id', (int) $sessionFundId)->lockForUpdate()->first() : null;
            if (! $fund) {
                throw new InputRejected(['fund_id' => 'Selecione um Fundo autorizado ao entrar no sistema.']);
            }
            if (isset($input['fund_id']) && (! is_scalar($input['fund_id']) || (string) $input['fund_id'] !== (string) $fund->id)) {
                throw new AccessDenied;
            }
            $input['fund_id'] = (int) $fund->id;
            if (Normalizer::name($fund->name) !== 'MUNICIPIO DE NIQUELANDIA') {
                if (! empty($input['department_id'])) {
                    throw new InputRejected(['department_id' => 'O Fundo da sessão não possui secretarias.']);
                }
                $input['department_id'] = null;
            }
        }
        if ($updating && $this->request->routeIs('contracts.update') && isset($input['department_id']) && ! is_scalar($input['department_id'])) {
            throw new InputRejected(['department_id' => 'Selecione uma secretaria válida.']);
        }
        if ($updating && $this->request->routeIs('contracts.update') && ! empty($input['department_id'])
            && (string) $input['department_id'] !== (string) $contract->department_id) {
            TransactionGuard::requireActive();
            $actor = User::findOrFail($context->actor->id);
            $sessionFundId = $this->request->session()->get('fund_id');
            $fund = is_numeric($sessionFundId) && $this->selectedFund->allows($actor, (int) $sessionFundId)
                ? DB::table('contract_funds')->where('id', (int) $sessionFundId)->lockForUpdate()->first() : null;
            if (! $fund || (int) $fund->id !== (int) $contract->fund_id || Normalizer::name($fund->name) !== 'MUNICIPIO DE NIQUELANDIA') {
                throw new InputRejected(['department_id' => 'O Fundo da sessão não permite selecionar uma secretaria.']);
            }
        }
        $input = $this->supplierInput($context, $contract, $input);
        $rules = ['fund_id' => [$updating ? 'sometimes' : 'required', 'nullable', 'integer', 'exists:contract_funds,id'],
            'department_id' => 'sometimes|nullable|integer|exists:contract_departments,id',
            'version' => $updating ? 'required|integer|min:1' : 'prohibited',
            'administrative_status' => ['sometimes', Rule::in(array_keys(Contract::STATUSES))],
            'reason' => $updating ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000',
            'duplicate_confirmation' => 'sometimes|boolean', 'number_year' => 'prohibited', 'number_sequence' => 'prohibited'];
        $required = ['numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot', 'objeto',
            'data_contrato', 'vigencia_inicio', 'vigencia_fim_original', 'tipo', 'valor_total', 'modalidade', 'n_licit'];
        $type = $input['tipo'] ?? $contract->tipo;
        if (is_string($type) && (! $updating || $type !== $contract->tipo)) {
            $required = array_merge($required, ContractTypeRule::where('name_key', Normalizer::name($type))->value('required_fields') ?? []);
        }
        $writable = [];
        foreach (ContractFields::MAP as $source => $definition) {
            $column = $definition['column'];
            if ($this->request->routeIs('contracts.store', 'contracts.update') && in_array($source, ContractFields::EXECUTION_SUMMARY)) {
                $rules[$column] = 'prohibited';

                continue;
            }
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
            if ($column === 'iptu') {
                $choices = ContractFields::IPTU_RESPONSIBILITIES;
                if ($updating && $contract->iptu !== null) {
                    $choices[] = $contract->iptu;
                }
                $rules[$column][] = Rule::in($choices);
            }
            if ($column === 'escopo') {
                $choices = ContractFields::SCOPE_OPTIONS;
                if ($updating && $contract->escopo !== null) {
                    $choices[] = $contract->escopo;
                }
                $rules[$column][] = Rule::in($choices);
            }
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

        if (array_key_exists('_catalog_supplier_id', $input)) {
            $fields['supplier_id'] = $input['_catalog_supplier_id'];
            if ($updating && ! $context->isAdministrator && array_key_exists('fornecedor_documento_snapshot', $input)
                && Normalizer::document($input['fornecedor_documento_snapshot']) !== Normalizer::document($contract->fornecedor_documento_snapshot)) {
                foreach (array_intersect(ContractFields::SUPPLIER_CATALOG, ContractFields::PERSONAL) as $column) {
                    $fields[$column] = null;
                }
            }
        }

        if ($this->request->routeIs('contracts.store', 'contracts.update')) {
            $fields = array_diff_key($fields, array_flip(ContractFields::CONTRACTOR_CATALOG));
            $actor = User::findOrFail($context->actor->id);
            $fundId = (int) ($data['fund_id'] ?? $contract->fund_id);
            $departmentId = array_key_exists('department_id', $data) ? $data['department_id'] : $contract->department_id;
            $departmentId = $departmentId === null ? null : (int) $departmentId;
            if (! $updating) {
                $defaults = $this->selectedFund->defaults($actor, $fundId, forPersistence: true);
                $fields += array_intersect_key($defaults, array_flip(ContractFields::CONTRACTOR_CATALOG));
            }
            $contextChanged = ! $updating || $fundId !== (int) $contract->fund_id || $departmentId !== $contract->department_id;
            if ($contextChanged || ! filled($contract->nome_fiscal)) {
                $fields = array_replace($fields, $this->inspectors->defaults($actor, $fundId, $departmentId, forSave: true));
            }
            $inspector = array_key_exists('nome_fiscal', $fields) ? $fields['nome_fiscal'] : $contract->nome_fiscal;
            if (! filled($inspector)) {
                throw new InputRejected(['nome_fiscal' => 'Fiscal é obrigatório. Selecione uma Secretaria com fiscal cadastrado ou confira o cadastro de fiscais do Fundo.']);
            }
        }

        return new ValidatedContractInput($data, $fields);
    }

    private function supplierInput(AuthorizedContract $context, Contract $contract, array $input): array
    {
        TransactionGuard::requireActive();
        unset($input['_catalog_supplier_id']);
        foreach (ContractFields::SUPPLIER_CATALOG as $column) {
            unset($input[$column]);
        }
        if ($contract->exists && (! array_key_exists('fornecedor_documento_snapshot', $input)
            || is_string($input['fornecedor_documento_snapshot']) && Normalizer::document($input['fornecedor_documento_snapshot']) === Normalizer::document($contract->fornecedor_documento_snapshot))) {
            $input['_catalog_supplier_id'] = $contract->supplier_id;

            return $input;
        }
        $document = $input['fornecedor_documento_snapshot'] ?? null;
        if (! is_string($document) || ! Normalizer::validDocument(Normalizer::document($document))) {
            return $input;
        }
        $actor = User::findOrFail($context->actor->id);
        $query = SupplierCatalog::visibleTo($actor)->where('document_key', Normalizer::document($document));
        if (isset($input['supplier_id'])) {
            $id = $this->validated($input, ['supplier_id' => 'required|integer|min:1']);
            $query->where('id', $id['supplier_id']);
        }
        $suppliers = $query->orderBy('id')->lockForUpdate()->limit(2)->get();
        if ($suppliers->count() !== 1) {
            throw new InputRejected(['fornecedor_documento_snapshot' => $suppliers->isEmpty()
                ? 'Selecione um fornecedor cadastrado e disponível para sua conta.'
                : 'Mais de um cadastro encontrado. Use Selecionar fornecedor cadastrado.']);
        }
        $supplier = $suppliers->first();
        $values = SupplierCatalog::defaults($actor, $supplier);
        foreach (ContractFields::SUPPLIER_CATALOG as $column) {
            if (array_key_exists($column, $values)) {
                $input[$column] = $values[$column];
            }
        }
        $input['_catalog_supplier_id'] = (int) $supplier->id;

        return $input;
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
