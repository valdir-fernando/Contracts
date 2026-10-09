<?php

namespace App\Support;

class ContractFields
{
    public const DERIVED = ['legacy_id', 'fundo_nome_snapshot', 'fundo_documento_snapshot', 'secretaria_nome_snapshot'];

    public const PERSONAL = ['rg_fornec', 'pis_pasep', 'cpf_socio', 'rg_socio', 'cpf_gestor', 'rg_gestor', 'cpf_fiscal', 'rg_fiscal'];

    public const MAP = [
        'ID_Contrato' => ['column' => 'legacy_id', 'type' => 'id', 'group' => 'Identificação e licitação'],
        'Contrato' => ['column' => 'numero', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'Cont_Inteiro' => ['column' => 'cont_inteiro', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'Data_Cont' => ['column' => 'data_contrato', 'type' => 'date', 'group' => 'Identificação e licitação'],
        'Data_Cont_Verd' => ['column' => 'data_cont_verd', 'type' => 'date', 'group' => 'Identificação e licitação'],
        'Processo' => ['column' => 'processo', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'Licitacao' => ['column' => 'modalidade', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'N_Licit' => ['column' => 'n_licit', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'Proc_Edital' => ['column' => 'proc_edital', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'N_Edital' => ['column' => 'n_edital', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'Data_Licit' => ['column' => 'data_licit', 'type' => 'date', 'group' => 'Identificação e licitação'],
        'N_Solicit' => ['column' => 'n_solicit', 'type' => 'text', 'group' => 'Identificação e licitação'],
        'CPF_CNPJ' => ['column' => 'fornecedor_documento_snapshot', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'Fornecedor' => ['column' => 'fornecedor_nome_snapshot', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'RG_Fornec' => ['column' => 'rg_fornec', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'Doc_Prof' => ['column' => 'doc_prof', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'PIS_PASEP' => ['column' => 'pis_pasep', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'End_Fornec' => ['column' => 'end_fornec', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'Socio' => ['column' => 'socio', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'CPF_Socio' => ['column' => 'cpf_socio', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'RG_Socio' => ['column' => 'rg_socio', 'type' => 'text', 'group' => 'Fornecedor e representante'],
        'CNPJ_Fundo' => ['column' => 'fundo_documento_snapshot', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Fundo' => ['column' => 'fundo_nome_snapshot', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'End_Fundo' => ['column' => 'end_fundo', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'CPF_Gestor' => ['column' => 'cpf_gestor', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Nome_Gestor' => ['column' => 'nome_gestor', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'RG_Gestor' => ['column' => 'rg_gestor', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Cargo_Gestor' => ['column' => 'cargo_gestor', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Secretaria' => ['column' => 'secretaria_nome_snapshot', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'CPF_Fiscal' => ['column' => 'cpf_fiscal', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Nome_Fiscal' => ['column' => 'nome_fiscal', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'RG_Fiscal' => ['column' => 'rg_fiscal', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Decreto_Fiscal' => ['column' => 'decreto_fiscal', 'type' => 'text', 'group' => 'Contratante gestão e fiscalização'],
        'Tipo_Contrato' => ['column' => 'tipo', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Escopo' => ['column' => 'escopo', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Tipo_Veiculo' => ['column' => 'tipo_veiculo', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Dados_Veiculo' => ['column' => 'dados_veiculo', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'End_Imovel' => ['column' => 'end_imovel', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Cargo_Credenc' => ['column' => 'cargo_credenc', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Local_Servico' => ['column' => 'local_servico', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'IPTU' => ['column' => 'iptu', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Instalacoes' => ['column' => 'instalacoes', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'AreaTerreno' => ['column' => 'areaterreno', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'AreaConstruida' => ['column' => 'areaconstruida', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Objeto' => ['column' => 'objeto', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Tipo_Fornecimento' => ['column' => 'tipo_fornecimento', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Vigencia' => ['column' => 'vigencia', 'type' => 'text', 'group' => 'Vigência execução e aditivos'],
        'Inicio_Vig' => ['column' => 'vigencia_inicio', 'type' => 'date', 'group' => 'Vigência execução e aditivos'],
        'Final_Vig' => ['column' => 'vigencia_fim_original', 'type' => 'date', 'group' => 'Vigência execução e aditivos'],
        'Final_Vig_Atualiz' => ['column' => 'vigencia_fim_atual', 'type' => 'date', 'group' => 'Vigência execução e aditivos'],
        'N_Aditivo' => ['column' => 'n_aditivo', 'type' => 'text', 'group' => 'Vigência execução e aditivos'],
        'Proc_Aditivo' => ['column' => 'proc_aditivo', 'type' => 'text', 'group' => 'Vigência execução e aditivos'],
        'VL_Parcial' => ['column' => 'vl_parcial', 'type' => 'money', 'group' => 'Financeiro garantia e dotação'],
        'VL_Total' => ['column' => 'valor_total', 'type' => 'money', 'group' => 'Financeiro garantia e dotação'],
        'VL_Acumulado' => ['column' => 'valor_acumulado', 'type' => 'money', 'group' => 'Financeiro garantia e dotação'],
        'VL_Saldo_Contrato' => ['column' => 'vl_saldo_contrato', 'type' => 'money', 'group' => 'Financeiro garantia e dotação'],
        'VL_Saldo_Disp' => ['column' => 'vl_saldo_disp', 'type' => 'money', 'group' => 'Financeiro garantia e dotação'],
        'Ext_Parcial' => ['column' => 'ext_parcial', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'Ext_Total' => ['column' => 'ext_total', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'Ext_Acumulado' => ['column' => 'ext_acumulado', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'Reforma' => ['column' => 'reforma', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Tipo_Obra' => ['column' => 'tipo_obra', 'type' => 'text', 'group' => 'Objeto e dados especiais'],
        'Tipo_Garantia' => ['column' => 'tipo_garantia', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        '%_Garantia' => ['column' => 'garantia', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'VL_Garantia' => ['column' => 'vl_garantia', 'type' => 'money', 'group' => 'Financeiro garantia e dotação'],
        'Ext_Garantia' => ['column' => 'ext_garantia', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'N_OS' => ['column' => 'n_os', 'type' => 'text', 'group' => 'Vigência execução e aditivos'],
        'Data_OS' => ['column' => 'data_os', 'type' => 'date', 'group' => 'Vigência execução e aditivos'],
        'Prazo_Obra' => ['column' => 'prazo_obra', 'type' => 'text', 'group' => 'Vigência execução e aditivos'],
        'Local_Public' => ['column' => 'local_public', 'type' => 'text', 'group' => 'Publicação distrato e paralisação'],
        'Data_Public' => ['column' => 'data_public', 'type' => 'date', 'group' => 'Publicação distrato e paralisação'],
        'Ficha' => ['column' => 'ficha', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'Empenho' => ['column' => 'empenho', 'type' => 'text', 'group' => 'Financeiro garantia e dotação'],
        'Proc_Distrato' => ['column' => 'proc_distrato', 'type' => 'text', 'group' => 'Publicação distrato e paralisação'],
        'Data_Distrato' => ['column' => 'data_distrato', 'type' => 'date', 'group' => 'Publicação distrato e paralisação'],
        'Tipo_Distrato' => ['column' => 'tipo_distrato', 'type' => 'text', 'group' => 'Publicação distrato e paralisação'],
        'Motivo_Distrato' => ['column' => 'motivo_distrato', 'type' => 'text', 'group' => 'Publicação distrato e paralisação'],
        'Proc_Paraliz_Obra' => ['column' => 'proc_paraliz_obra', 'type' => 'text', 'group' => 'Publicação distrato e paralisação'],
        'Data_Paraliz_Obra' => ['column' => 'data_paraliz_obra', 'type' => 'date', 'group' => 'Publicação distrato e paralisação'],
        'Motivo_Paraliz_Obra' => ['column' => 'motivo_paraliz_obra', 'type' => 'text', 'group' => 'Publicação distrato e paralisação'],
    ];

    public static function label(string $source): string
    {
        return self::LABELS[$source] ?? str_replace('_', ' ', $source);
    }

    public static function labelColumn(string $column): string
    {
        foreach (self::MAP as $source => $definition) {
            if ($definition['column'] === $column) {
                return self::label($source);
            }
        }

        return match ($column) {
            'fund_id' => 'Fundo cadastrado', 'department_id' => 'Secretaria cadastrada', 'supplier_id' => 'Fornecedor cadastrado',
            'supplier_document_key' => 'Documento normalizado do fornecedor', 'exercicio' => 'Exercício',
            'ownership_resolved' => 'Órgão conciliado', 'vigencia_em_revisao' => 'Vigência em revisão',
            'quality_issues' => 'Ocorrências de qualidade', default => str_replace('_', ' ', $column),
        };
    }

    public const LABELS = [
        'ID_Contrato' => 'Código de origem', 'Contrato' => 'Número do contrato',
        'Cont_Inteiro' => 'Identificação completa', 'Data_Cont' => 'Data do contrato',
        'Data_Cont_Verd' => 'Data do contrato (legado)', 'N_Licit' => 'Número da licitação',
        'Licitacao' => 'Modalidade', 'Proc_Edital' => 'Processo do edital', 'N_Edital' => 'Número do edital',
        'Data_Licit' => 'Data da licitação', 'N_Solicit' => 'Número da solicitação',
        'CPF_CNPJ' => 'CPF/CNPJ do fornecedor', 'RG_Fornec' => 'RG do fornecedor',
        'Doc_Prof' => 'Documento profissional', 'PIS_PASEP' => 'PIS/PASEP',
        'End_Fornec' => 'Endereço do fornecedor', 'Socio' => 'Sócio / representante',
        'CPF_Socio' => 'CPF do sócio', 'RG_Socio' => 'RG do sócio', 'CNPJ_Fundo' => 'CNPJ do fundo',
        'End_Fundo' => 'Endereço do fundo', 'CPF_Gestor' => 'CPF do gestor', 'Nome_Gestor' => 'Gestor',
        'RG_Gestor' => 'RG do gestor', 'Cargo_Gestor' => 'Cargo do gestor', 'CPF_Fiscal' => 'CPF do fiscal',
        'Nome_Fiscal' => 'Fiscal', 'RG_Fiscal' => 'RG do fiscal', 'Decreto_Fiscal' => 'Decreto do fiscal',
        'Tipo_Contrato' => 'Tipo de contrato', 'Tipo_Veiculo' => 'Tipo de veículo', 'Dados_Veiculo' => 'Dados do veículo',
        'End_Imovel' => 'Endereço do imóvel', 'Cargo_Credenc' => 'Cargo de credenciamento',
        'Local_Servico' => 'Local do serviço', 'Instalacoes' => 'Instalações', 'AreaTerreno' => 'Área do terreno',
        'AreaConstruida' => 'Área construída', 'Objeto' => 'Objeto', 'Tipo_Fornecimento' => 'Tipo de fornecimento',
        'Vigencia' => 'Vigência por extenso', 'Inicio_Vig' => 'Início da vigência',
        'Final_Vig' => 'Término original', 'Final_Vig_Atualiz' => 'Término atualizado informado',
        'N_Aditivo' => 'Número de aditivo (resumo legado)', 'Proc_Aditivo' => 'Processo do aditivo',
        'VL_Parcial' => 'Valor parcial informado', 'VL_Total' => 'Valor inicial', 'VL_Acumulado' => 'Valor acumulado',
        'VL_Saldo_Contrato' => 'Saldo do contrato informado', 'VL_Saldo_Disp' => 'Saldo disponível informado',
        'Ext_Parcial' => 'Valor parcial por extenso', 'Ext_Total' => 'Valor inicial por extenso',
        'Ext_Acumulado' => 'Valor acumulado por extenso', 'Tipo_Obra' => 'Tipo de obra',
        'Tipo_Garantia' => 'Tipo de garantia', '%_Garantia' => 'Percentual de garantia informado',
        'VL_Garantia' => 'Valor da garantia', 'Ext_Garantia' => 'Garantia por extenso', 'N_OS' => 'Número da ordem de serviço',
        'Data_OS' => 'Data da ordem de serviço', 'Prazo_Obra' => 'Prazo da obra', 'Local_Public' => 'Local de publicação',
        'Data_Public' => 'Data de publicação', 'Proc_Distrato' => 'Processo do distrato', 'Data_Distrato' => 'Data do distrato',
        'Tipo_Distrato' => 'Tipo de distrato', 'Motivo_Distrato' => 'Motivo do distrato',
        'Proc_Paraliz_Obra' => 'Processo de paralisação', 'Data_Paraliz_Obra' => 'Data de paralisação',
        'Motivo_Paraliz_Obra' => 'Motivo da paralisação',
    ];
}
