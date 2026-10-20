<?php

namespace App\Infrastructure\Companies;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CnpjLookup
{
    public function find(string $cnpj): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])->get('https://brasilapi.com.br/api/cnpj/v1/'.$cnpj);
        } catch (ConnectionException) {
            return ['status' => 'unavailable'];
        }
        if ($response->status() === 404) {
            return ['status' => 'not_found'];
        }
        $raw = $response->json();
        if (! $response->successful() || ! is_array($raw)
            || ($raw['cnpj'] ?? null) !== $cnpj || ! is_string($raw['razao_social'] ?? null)
            || trim($raw['razao_social']) === '') {
            return ['status' => 'unavailable'];
        }
        $text = static fn (string $key, int $max = 255): string => is_string($raw[$key] ?? null)
            ? mb_substr(trim($raw[$key]), 0, $max) : '';
        $address = implode(', ', array_filter([$text('descricao_tipo_de_logradouro'), $text('logradouro'),
            $text('numero'), $text('complemento'), $text('bairro'), $text('municipio'), $text('uf'), $text('cep')],
            static fn ($part) => $part !== ''));
        $phones = [];
        foreach (['ddd_telefone_1', 'ddd_telefone_2'] as $key) {
            $phone = preg_replace('/\D/', '', $text($key));
            if (preg_match('/^\d{10,11}$/D', $phone)) {
                $phones[strlen($phone) === 11 ? 'Fone_Celular' : 'Fone_Fixo'] ??= $phone;
            }
        }
        $email = $text('email');
        $supplier = ['name' => $text('razao_social'), 'Nome_Fantasia' => $text('nome_fantasia'),
            'Endereco' => mb_substr($address, 0, 2000),
            'Email_Fornecedor' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''] + $phones;

        $partners = [];
        foreach (is_array($raw['qsa'] ?? null) ? $raw['qsa'] : [] as $partner) {
            if (! is_array($partner) || ! is_string($partner['nome_socio'] ?? null) || trim($partner['nome_socio']) === '') {
                continue;
            }
            $partners[] = ['name' => mb_substr(trim($partner['nome_socio']), 0, 255),
                'qualification' => is_string($partner['qualificacao_socio'] ?? null)
                    ? mb_substr(trim($partner['qualificacao_socio']), 0, 255) : ''];
        }

        return ['status' => 'found', 'source' => 'BrasilAPI — dados públicos do CNPJ', 'cnpj' => $cnpj,
            'partners' => $partners,
            'registration_status' => $text('descricao_situacao_cadastral'),
            'supplier' => $supplier,
            'fund' => ['name' => $supplier['name'], 'End_Fundo' => $supplier['Endereco'],
                'Telefone' => $phones['Fone_Fixo'] ?? $phones['Fone_Celular'] ?? '']];
    }
}
