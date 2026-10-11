<?php

namespace App\Support;

class AmendmentFields
{
    // ID is the native primary key. The other 43 source fields remain explicit.
    public const MAP = [
        'N_ADT' => ['label' => 'Número do aditivo', 'type' => 'text', 'group' => 'Identificação'],
        'Data_ADT' => ['label' => 'Data do aditivo', 'type' => 'date', 'group' => 'Identificação'],
        'Proc_ADT' => ['label' => 'Processo do aditivo', 'type' => 'text', 'group' => 'Identificação'],
        'Contrato' => ['label' => 'Contrato', 'type' => 'text', 'group' => 'Partes', 'column' => 'numero'],
        'CNPJ_Fornec' => ['label' => 'CPF/CNPJ do fornecedor', 'type' => 'text', 'group' => 'Partes', 'column' => 'fornecedor_documento_snapshot'],
        'Fornecedor' => ['label' => 'Fornecedor', 'type' => 'text', 'group' => 'Partes', 'column' => 'fornecedor_nome_snapshot'],
        'RG_Fornecedor' => ['label' => 'RG do fornecedor', 'type' => 'text', 'group' => 'Partes', 'column' => 'rg_fornec'],
        'Doc_Prof' => ['label' => 'Documento profissional', 'type' => 'text', 'group' => 'Partes', 'column' => 'doc_prof'],
        'End_Fornecedor' => ['label' => 'Endereço do fornecedor', 'type' => 'text', 'group' => 'Partes', 'column' => 'end_fornec'],
        'CPF_Socio' => ['label' => 'CPF do sócio', 'type' => 'text', 'group' => 'Partes', 'column' => 'cpf_socio'],
        'RG_Socio' => ['label' => 'RG do sócio', 'type' => 'text', 'group' => 'Partes', 'column' => 'rg_socio'],
        'Nome_Socio' => ['label' => 'Nome do sócio', 'type' => 'text', 'group' => 'Partes', 'column' => 'socio'],
        'CNPJ_Fundo' => ['label' => 'Documento do fundo', 'type' => 'text', 'group' => 'Partes', 'column' => 'fundo_documento_snapshot'],
        'Fundo' => ['label' => 'Fundo', 'type' => 'text', 'group' => 'Partes', 'column' => 'fundo_nome_snapshot'],
        'End_Fundo' => ['label' => 'Endereço do fundo', 'type' => 'text', 'group' => 'Partes', 'column' => 'end_fundo'],
        'Secretaria' => ['label' => 'Secretaria', 'type' => 'text', 'group' => 'Partes', 'column' => 'secretaria_nome_snapshot'],
        'CPF_Gestor' => ['label' => 'CPF do gestor', 'type' => 'text', 'group' => 'Partes', 'column' => 'cpf_gestor'],
        'RG_Gestor' => ['label' => 'RG do gestor', 'type' => 'text', 'group' => 'Partes', 'column' => 'rg_gestor'],
        'Gestor' => ['label' => 'Gestor', 'type' => 'text', 'group' => 'Partes', 'column' => 'nome_gestor'],
        'Cargo_Gestor' => ['label' => 'Cargo do gestor', 'type' => 'text', 'group' => 'Partes', 'column' => 'cargo_gestor'],
        'Tipo_ADT' => ['label' => 'Tipo do aditivo', 'type' => 'text', 'group' => 'Fundamentação'],
        'Lei' => ['label' => 'Lei informada', 'type' => 'text', 'group' => 'Fundamentação'],
        'Motivo_ADT' => ['label' => 'Motivo do aditivo', 'type' => 'text', 'group' => 'Fundamentação'],
        'Tipo_Alteracao_Valor' => ['label' => 'Tipo de alteração de valor', 'type' => 'text', 'group' => 'Fundamentação'],
        'Artigos' => ['label' => 'Artigos informados', 'type' => 'text', 'group' => 'Fundamentação'],
        'Texto_Artigos' => ['label' => 'Texto dos artigos', 'type' => 'text', 'group' => 'Fundamentação'],
        'Porcentagem' => ['label' => 'Porcentagem informada', 'type' => 'text', 'group' => 'Fundamentação'],
        'Incio_Prorrog' => ['label' => 'Início da prorrogação', 'type' => 'date', 'group' => 'Períodos'],
        'Final_Prorrog' => ['label' => 'Fim da prorrogação', 'type' => 'date', 'group' => 'Períodos'],
        'Periodo_Prorrog' => ['label' => 'Período da prorrogação por extenso', 'type' => 'text', 'group' => 'Períodos'],
        'Incio_Servico' => ['label' => 'Início do serviço', 'type' => 'date', 'group' => 'Períodos'],
        'Final_Servico' => ['label' => 'Fim do serviço', 'type' => 'date', 'group' => 'Períodos'],
        'Periodo_Servico' => ['label' => 'Período do serviço por extenso', 'type' => 'text', 'group' => 'Períodos'],
        'VL_Mensal' => ['label' => 'Valor mensal informado', 'type' => 'money', 'group' => 'Financeiro e execução'],
        'VL_ADT' => ['label' => 'Valor do aditivo informado', 'type' => 'money', 'group' => 'Financeiro e execução'],
        'VL_Acumulado' => ['label' => 'Valor acumulado informado', 'type' => 'money', 'group' => 'Financeiro e execução'],
        'Ext_Mensal' => ['label' => 'Valor mensal por extenso', 'type' => 'text', 'group' => 'Financeiro e execução'],
        'Ext_Aditivo' => ['label' => 'Valor do aditivo por extenso', 'type' => 'text', 'group' => 'Financeiro e execução'],
        'Ext_Acumulado' => ['label' => 'Valor acumulado por extenso', 'type' => 'text', 'group' => 'Financeiro e execução'],
        'N_OS' => ['label' => 'Número da ordem de serviço', 'type' => 'text', 'group' => 'Financeiro e execução'],
        'Data_OS' => ['label' => 'Data da ordem de serviço', 'type' => 'date', 'group' => 'Financeiro e execução'],
        'Ficha' => ['label' => 'Ficha informada', 'type' => 'text', 'group' => 'Financeiro e execução'],
        'Empenho' => ['label' => 'Empenho informado', 'type' => 'text', 'group' => 'Financeiro e execução'],
    ];

    public const REQUIRED = ['N_ADT', 'Data_ADT', 'Proc_ADT', 'Tipo_ADT', 'Motivo_ADT'];

    public const DERIVED = ['Contrato', 'CNPJ_Fornec', 'Fornecedor', 'CNPJ_Fundo', 'Fundo', 'Secretaria'];

    public const PERSONAL = ['RG_Fornecedor', 'Doc_Prof', 'CPF_Socio', 'RG_Socio', 'CPF_Gestor', 'RG_Gestor'];
}
