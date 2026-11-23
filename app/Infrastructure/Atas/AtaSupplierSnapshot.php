<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\CatalogAccess;
use App\Models\Contract;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Services\SupplierCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AtaSupplierSnapshot
{
    public function capture(User $actor, string $document): array
    {
        TransactionGuard::requireActive();
        $digits = Normalizer::document($document);
        if (! preg_match('/^[\d\s.\/\-]+$/D', $document) || ! Normalizer::validDocument($digits)) {
            throw ValidationException::withMessages(['fornecedor_documento_snapshot' => 'Dados do Fornecedor: informe um CPF/CNPJ válido.']);
        }
        Gate::forUser($actor)->authorize('selectSupplier', Contract::class);
        $query = $actor->canAccessCatalog('supplier') ? CatalogAccess::suppliers($actor) : SupplierCatalog::visibleTo($actor);
        $matches = $query->where('document_key', $digits)->orderBy('id')->lockForUpdate()->get(['id', 'name', 'raw_payload']);
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['fornecedor_documento_snapshot' => 'Dados do Fornecedor: selecione um cadastro único e autorizado para este CPF/CNPJ.']);
        }
        $supplier = $matches->first();
        $raw = json_decode($supplier->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        $fields = ['FORNECEDOR' => $supplier->name, 'CNPJ_FORNECEDOR' => $digits];
        foreach (['END_FORNECEDOR' => 'Endereco', 'EMAIL' => 'Email_Fornecedor', 'TELEFONE1' => 'Fone_Celular',
            'TELEFONE2' => 'WhatsApp_Fornecedor', 'SOCIO' => 'Nome_Socio', 'CPF_SOCIO' => 'CPF_Socio',
            'RG_SOCIO' => 'RG_Socio', 'RG_FORNECEDOR' => 'RG', 'DOC_PROF' => 'Doc_Prof', 'PIS_PASEP' => 'PIS_PASEP'] as $column => $source) {
            $fields[$column] = is_string($raw[$source] ?? null) ? trim($raw[$source]) : null;
        }
        $required = ['FORNECEDOR' => 'Nome / Razão Social', 'END_FORNECEDOR' => 'Endereço do fornecedor', 'EMAIL' => 'e-mail'];
        $required += strlen($digits) === 14 ? ['SOCIO' => 'Sócio / representante', 'CPF_SOCIO' => 'CPF do sócio', 'RG_SOCIO' => 'RG do sócio'] : ['RG_FORNECEDOR' => 'RG do fornecedor'];
        $this->requiredSnapshot($fields, 'Dados do Fornecedor', $required);
        Validator::make($fields, ['EMAIL' => 'required|email:rfc|max:254',
            'TELEFONE1' => 'required_without:TELEFONE2|nullable|string', 'TELEFONE2' => 'required_without:TELEFONE1|nullable|string'],
            ['TELEFONE1.required_without' => 'Dados do Fornecedor: informe Celular ou WhatsApp no cadastro.',
                'TELEFONE2.required_without' => 'Dados do Fornecedor: informe Celular ou WhatsApp no cadastro.',
                'EMAIL.email' => 'Dados do Fornecedor: informe um e-mail válido no cadastro.'])->validate();
        if (strlen($digits) === 14) {
            $cpf = Normalizer::document($fields['CPF_SOCIO']);
            if (strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                throw ValidationException::withMessages(['CPF_SOCIO' => 'Dados do Fornecedor: informe um CPF válido para o sócio no cadastro.']);
            }
            $fields['CPF_SOCIO'] = $cpf;
        }

        return $fields;
    }

    private function requiredSnapshot(array $values, string $group, array $labels): void
    {
        $errors = [];
        foreach ($labels as $column => $label) {
            if (! is_scalar($values[$column] ?? null) || trim((string) $values[$column]) === '') {
                $errors[$column] = $group.': preencha '.$label.' no cadastro.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
