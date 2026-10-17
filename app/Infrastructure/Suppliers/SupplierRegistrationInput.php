<?php

namespace App\Infrastructure\Suppliers;

use App\Services\Legacy\Normalizer;
use App\Support\SupplierFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupplierRegistrationInput
{
    public function details(array $input, string $bag = 'catalog-supplier'): array
    {
        $rules = [];
        foreach (SupplierFields::fields() as $field => [$label, $type]) {
            $rules[$field] = [$field === 'Endereco' ? 'required' : 'nullable', 'string', 'max:'.($type === 'textarea' ? 2000 : 255)];
            if ($type === 'email') {
                $rules[$field][] = 'email:rfc';
            } elseif ($type === 'date') {
                $rules[$field][] = 'date_format:Y-m-d';
            } elseif ($field === 'Sexo') {
                $rules[$field][] = Rule::in(['Masculino', 'Feminino']);
            } elseif ($type === 'tel') {
                $rules[$field][] = 'regex:/^(?:\d{10,11}|\(\d{2}\) \d{4,5}-\d{4})$/D';
            } elseif ($field === 'PIS_PASEP') {
                $rules[$field][] = 'regex:/^(?:\d{11}|\d{3}\.\d{5}\.\d{2}-\d)$/D';
            }
        }
        try {
            $details = Validator::make($input, $rules)->validate();
        } catch (ValidationException $error) {
            throw $error->errorBag($bag);
        }
        foreach (SupplierFields::fields() as $field => [$label, $type]) {
            if (isset($details[$field]) && ($type === 'tel' || $field === 'PIS_PASEP')) {
                $details[$field] = preg_replace('/\D/', '', $details[$field]);
            }
        }
        if (! empty($details['CPF_Socio'])) {
            $cpf = Normalizer::document($details['CPF_Socio']);
            if (! preg_match('/^[\d\s.\/-]+$/D', $details['CPF_Socio']) || strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                throw ValidationException::withMessages(['CPF_Socio' => 'Informe um CPF válido.'])->errorBag($bag);
            }
            $details['CPF_Socio'] = $cpf;
        }

        return $details;
    }
}
