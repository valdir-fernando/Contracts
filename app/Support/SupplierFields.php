<?php

namespace App\Support;

final class SupplierFields
{
    public const GROUPS = [
        'Dados cadastrais' => [
            'Nome_Fantasia' => ['Nome fantasia', 'text'],
            'RG' => ['RG', 'text'],
            'Data_Nasc' => ['Data de nascimento', 'date'],
            'Sexo' => ['Sexo', 'select'],
            'PIS_PASEP' => ['PIS/PASEP', 'text'],
            'Doc_Prof' => ['Documento profissional', 'text'],
        ],
        'Endereço e contatos do fornecedor' => [
            'Endereco' => ['Endereço completo', 'textarea'],
            'Fone_Fixo' => ['Telefone fixo', 'tel'],
            'Fone_Celular' => ['Celular', 'tel'],
            'WhatsApp_Fornecedor' => ['WhatsApp', 'tel'],
            'Email_Fornecedor' => ['E-mail', 'email'],
        ],
        'Sócio' => [
            'Nome_Socio' => ['Nome do sócio', 'text'],
            'CPF_Socio' => ['CPF do sócio', 'text'],
            'RG_Socio' => ['RG do sócio', 'text'],
            'Celular_Socio' => ['Celular do sócio', 'tel'],
            'Fixo_Socio' => ['Telefone fixo do sócio', 'tel'],
            'WhatsApp_Socio' => ['WhatsApp do sócio', 'tel'],
            'Email_Socio' => ['E-mail do sócio', 'email'],
        ],
        'Pessoa de contato' => [
            'Nome_Contato' => ['Nome do contato', 'text'],
            'Celular_Contato' => ['Celular do contato', 'tel'],
            'WhatsApp_Contato' => ['WhatsApp do contato', 'tel'],
            'Email_Contato' => ['E-mail do contato', 'email'],
        ],
    ];

    public static function fields(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }
}
