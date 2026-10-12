<?php

namespace App\Support;

use App\Models\Contract;
use App\Services\Reports\ContractReport;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class BrazilianLocale
{
    public const MONTHS = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public const ACTIONS = [
        'created' => 'Cadastro criado', 'updated' => 'Cadastro atualizado', 'deleted' => 'Registro excluído', 'restored' => 'Registro restaurado',
        'login' => 'Entrada no sistema', 'logout' => 'Saída do sistema', 'login_failed' => 'Tentativa de entrada sem sucesso',
        'password_reset' => 'Senha redefinida', 'account_updated' => 'Conta atualizada', 'own_password_changed' => 'Senha alterada pelo titular',
        'admin_provisioned' => 'Administrador cadastrado', 'catalog_created' => 'Cadastro auxiliar criado',
        'review_confirmed' => 'Revisão confirmada', 'history_linked' => 'Vínculo histórico confirmado', 'history_imported' => 'Histórico importado pelo sistema',
        'expiration_preferences_updated' => 'Preferências de alerta atualizadas', 'contract_type_rule_updated' => 'Regra de tipo de contrato atualizada',
        'report_requested' => 'Relatório solicitado', 'report_exported' => 'Exportação solicitada', 'report_generated' => 'Relatório gerado', 'report_downloaded' => 'Relatório baixado',
        'audit_exported' => 'Auditoria exportada', 'contract_printed' => 'Contrato impresso',
        'report_preset_created' => 'Consulta salva', 'report_preset_deleted' => 'Consulta salva excluída',
        'internal_api_list' => 'Consulta à lista pela API interna', 'internal_api_detail' => 'Consulta ao contrato pela API interna',
        'legacy_sync_completed' => 'Sincronização do legado concluída', 'legacy_sync_failed' => 'Sincronização do legado interrompida',
        'amendment_created' => 'Aditivo criado', 'amendment_updated' => 'Aditivo atualizado', 'amendment_deleted' => 'Aditivo excluído', 'amendment_restored' => 'Aditivo restaurado',
        'financial_created' => 'Registro financeiro criado', 'financial_updated' => 'Registro financeiro atualizado', 'financial_deleted' => 'Registro financeiro excluído', 'financial_restored' => 'Registro financeiro restaurado',
        'attachment_uploaded' => 'Documento anexado', 'attachment_downloaded' => 'Documento baixado', 'attachment_deleted' => 'Documento removido', 'attachment_restored' => 'Documento restaurado',
    ];

    public static function action(string $code): string
    {
        return self::ACTIONS[$code] ?? 'Outra ação';
    }

    public static function state(string $code): string
    {
        return ['running' => 'Em andamento', 'simulated' => 'Simulado', 'completed' => 'Concluído', 'failed' => 'Falhou', 'matched' => 'Vínculo identificado', 'manual_confirmed' => 'Vínculo confirmado manualmente', 'ambiguous' => 'Vínculo ambíguo', 'unmatched' => 'Sem vínculo identificado', 'amendment' => 'Aditivo', 'item' => 'Item'][$code] ?? 'Não classificado';
    }

    // String operations preserve all four decimal places without float conversion.
    public static function decimal(?string $value, bool $group = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        [$integer, $fraction] = explode('.', (string) BigDecimal::of($value)->toScale(4));
        if ($group) {
            $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
        }

        return $integer.','.$fraction;
    }

    public static function reportRow(array $row, array $filters, bool $pdf = false): array
    {
        foreach ($filters['columns'] ?? ContractReport::COLUMNS as $index => $column) {
            if ($column === 'initial_amount') {
                $row[$index] = self::decimal($row[$index], $pdf);
            } elseif (in_array($column, ['signed_at', 'starts_at', 'ends_at'], true) && $row[$index]) {
                $row[$index] = CarbonImmutable::parse($row[$index])->format('d/m/Y');
            }
        }

        return $row;
    }

    public static function metadata(array $metadata): array
    {
        $labels = ['contract_id' => 'Contrato', 'export_id' => 'Exportação', 'preset_id' => 'Consulta salva', 'format' => 'Formato', 'count' => 'Quantidade', 'page' => 'Página', 'filters' => 'Filtros', 'enabled' => 'Alertas ativados', 'days' => 'Antecedência em dias', 'kind' => 'Tipo de cadastro', 'id' => 'Identificador', 'reason' => 'Motivo', 'source_hash' => 'Identificador da origem', 'include_personal' => 'Inclusão de dados pessoais', 'required_fields' => 'Campos obrigatórios'];
        $display = [];
        foreach ($metadata as $key => $value) {
            $display[$labels[$key] ?? $key] = match (true) {
                $key === 'filters' && is_array($value) => self::filters($value),
                $key === 'format' && is_string($value) => strtoupper($value),
                $key === 'kind' && is_string($value) => ['fund' => 'Fundo', 'department' => 'Secretaria', 'supplier' => 'Fornecedor', 'scope' => 'Órgão'][$value] ?? self::state($value),
                is_bool($value) => $value ? 'Sim' : 'Não',
                is_array($value) => self::metadata($value),
                default => $value,
            };
        }

        return $display;
    }

    public static function filters(array $filters): string
    {
        $labels = require lang_path('pt_BR/validation.php');
        $parts = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $label = $labels['attributes'][$key] ?? ['review' => 'pendências de revisão', 'deleted' => 'registros excluídos'][$key] ?? 'Critério';
            $value = match ($key) {
                'action' => self::action($value), 'report' => ContractReport::TYPES[$value] ?? $value,
                'status' => Contract::STATUSES[$value] ?? $value, 'validity' => Contract::VALIDITY[$value] ?? $value,
                'from', 'to', 'reference' => CarbonImmutable::parse($value)->format('d/m/Y'),
                'include_personal', 'review', 'deleted' => $value ? 'Sim' : 'Não',
                'direction' => $value === 'desc' ? 'Decrescente' : 'Crescente',
                'sort' => ContractFields::labelColumn($value),
                'date_field' => ['data_contrato' => 'Assinatura', 'vigencia_inicio' => 'Início da vigência', 'end' => 'Término informado'][$value] ?? 'Período',
                'columns' => implode(', ', app(ContractReport::class)->headers($filters)),
                default => is_array($value) ? implode(', ', $value) : (string) $value,
            };
            $parts[] = ucfirst($label).': '.$value;
        }

        return implode('; ', $parts) ?: 'Sem filtros adicionais';
    }
}
