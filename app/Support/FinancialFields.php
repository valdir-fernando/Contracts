<?php

namespace App\Support;

use App\Models\Contract;

class FinancialFields
{
    public const KINDS = ['dotacao' => 'Dotação', 'ficha' => 'Valor por ficha', 'empenho' => 'Empenho'];

    // Source primary keys are represented by the native record ID. No automatic legacy linking.
    public const MAP = [
        'dotacao' => [
            'Orgao' => ['Órgão', 'text'], 'Gestao' => ['Gestão', 'text'], 'Unidade' => ['Unidade', 'text'],
            'Funcao' => ['Função', 'text'], 'SubFunc' => ['Subfunção', 'text'], 'Programa' => ['Programa', 'text'],
            'Projeto' => ['Projeto/atividade', 'text'], 'Fonte' => ['Fonte', 'text'], 'Codigo' => ['Código', 'text'],
            'Ficha' => ['Ficha', 'text'], 'Descricao' => ['Descrição', 'textarea'], 'Dotacao' => ['Dotação informada', 'text'],
            'Fundo' => ['Fundo', 'readonly', 'fundo_nome_snapshot'], 'Secretaria' => ['Secretaria', 'readonly', 'secretaria_nome_snapshot'],
        ],
        'ficha' => [
            'Fundo' => ['Fundo', 'readonly', 'fundo_nome_snapshot'], 'Contrato' => ['Contrato', 'readonly', 'numero'],
            'Ficha' => ['Ficha', 'text'], 'Valor' => ['Valor informado', 'money'],
            'CPF_CNPJ' => ['CPF/CNPJ do fornecedor', 'readonly-document', 'fornecedor_documento_snapshot'],
            'Proc_Cont' => ['Processo do contrato', 'readonly', 'processo'], 'Proc_ADT' => ['Processo do aditivo informado', 'text'],
            'N_ADT' => ['Número do aditivo informado', 'text'],
        ],
        'empenho' => [
            'Empenho' => ['Número do empenho', 'text'], 'Ficha' => ['Ficha informada', 'text'],
            'Valor' => ['Valor informado', 'money'], 'Objeto' => ['Objeto do empenho', 'textarea'],
            'Processo' => ['Processo do empenho', 'text'], 'Observacao' => ['Observação', 'textarea'],
            'Contrato' => ['Contrato', 'readonly', 'numero'], 'Fundo' => ['Fundo', 'readonly', 'fundo_nome_snapshot'],
            'Secretaria' => ['Secretaria', 'readonly', 'secretaria_nome_snapshot'],
            'CPF_CNPJ' => ['CPF/CNPJ do fornecedor', 'readonly-document', 'fornecedor_documento_snapshot'],
        ],
    ];

    public const REQUIRED = ['dotacao' => ['Ficha', 'Descricao', 'Dotacao'], 'ficha' => ['Ficha', 'Valor'], 'empenho' => ['Empenho', 'Valor', 'Objeto']];

    public static function seed(string $kind, Contract $contract): array
    {
        $payload = [];
        foreach (self::MAP[$kind] as $field => $definition) {
            $payload[$field] = isset($definition[2]) ? $contract->{$definition[2]} : null;
        }

        return $payload;
    }
}
