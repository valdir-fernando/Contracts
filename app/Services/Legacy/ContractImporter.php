<?php

namespace App\Services\Legacy;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Support\ContractFields;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ContractImporter
{
    private array $issues = [];

    private array $catalogs = [];

    private array $stats = [];

    private ImportRun $run;

    private const CATALOGS = [
        'tb_Fundo' => ['table' => 'contract_funds', 'id' => 'ID_Fundo', 'name' => 'Fundo', 'document' => 'CNPJ_Fundo'],
        'tb_Secretaria' => ['table' => 'contract_departments', 'id' => 'ID_Secretaria', 'name' => 'Secretaria'],
        'tb_Fornecedor' => ['table' => 'suppliers', 'id' => 'ID_Fornecedor', 'name' => 'Nome_Fornecedor', 'document' => 'CPF_CNPJ'],
    ];

    public function import(string $path, bool $dryRun = false): ImportRun
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo de referência indisponível.');
        }
        $this->issues = [];
        $this->catalogs = [];
        $this->stats = [];
        $runData = ['source_hash' => hash_file('sha256', $path), 'status' => 'running', 'started_at' => now()];
        if (! $dryRun) {
            $this->run = ImportRun::create($runData);
        }
        DB::beginTransaction();
        try {
            if ($dryRun) {
                $this->run = ImportRun::create($runData);
            }
            $reader = new SqlDumpReader;
            foreach ($reader->rows($path, array_keys(self::CATALOGS)) as $row) {
                $this->catalog($row['table'], $row['data']);
            }
            $this->loadCatalogs();
            $this->resolveUserScopes();
            $counts = ['source_count' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'rejected_count' => 0];
            foreach ($reader->rows($path, ['TB_Contratos']) as $row) {
                $counts['source_count']++;
                $outcome = $this->contract($row['data']);
                $counts[$outcome.'_count']++;
            }
            if ($counts['source_count'] === 0) {
                throw new RuntimeException('Nenhum contrato encontrado na referência.');
            }
            foreach (array_chunk($this->issues, 300) as $chunk) {
                DB::table('import_issues')->insert($chunk);
            }
            $byCode = array_count_values(array_column($this->issues, 'code'));
            $this->run->fill($counts + ['issues_count' => count($this->issues), 'status' => $dryRun ? 'simulated' : 'completed',
                'summary' => ['catalogs' => $this->stats, 'issues_by_code' => $byCode], 'finished_at' => now()])->save();
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $this->run;
        } catch (Throwable $exception) {
            DB::rollBack();
            if (! $dryRun) {
                $this->run->update(['status' => 'failed', 'finished_at' => now(), 'summary' => ['error' => 'Importação interrompida; nenhuma alteração de dados foi aplicada.']]);
            }
            // Do not print query bindings or raw source data in console/log output.
            throw new RuntimeException(get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Não foi possível concluir a importação. Nenhuma alteração de dados foi aplicada.');
        }
    }

    private function record(string $table, string $id, array $raw): LegacyRecord
    {
        $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return LegacyRecord::firstOrCreate(['source_table' => $table, 'legacy_id' => $id, 'source_hash' => hash('sha256', $json)],
            ['raw_payload' => $raw, 'import_run_id' => $this->run->id]);
    }

    private function issue(?LegacyRecord $record, string $field, string $code, string $message, string $severity = 'warning', ?string $scopeId = null): array
    {
        $issue = ['field' => $field, 'code' => $code, 'message' => $message];
        $this->issues[] = $issue + ['import_run_id' => $this->run->id, 'legacy_record_id' => $record?->id,
            'source_table' => $record?->source_table ?? 'access_scopes', 'legacy_id' => $record?->legacy_id ?? $scopeId,
            'severity' => $severity];

        return $issue;
    }

    private function validId(?string $id): bool
    {
        return $id !== null && ctype_digit($id) && (int) $id > 0 && (int) $id <= 4294967295;
    }

    private function catalog(string $source, array $raw): void
    {
        $config = self::CATALOGS[$source];
        $this->stats[$source] ??= ['source' => 0, 'imported' => 0, 'skipped' => 0, 'rejected' => 0];
        $this->stats[$source]['source']++;
        $id = (string) ($raw[$config['id']] ?? 'missing');
        $record = $this->record($source, $id, $raw);
        $name = trim($raw[$config['name']] ?? '');
        $nameKey = Normalizer::name($name);
        if (! $this->validId($id) || $nameKey === '' || strlen($nameKey) > 255) {
            $this->issue($record, $config['id'], 'invalid_catalog', 'Identificador ou nome de catálogo inválido.', 'error');
            $record->update(['status' => 'rejected']);
            $this->stats[$source]['rejected']++;

            return;
        }
        $existing = DB::table($config['table'])->where('legacy_id', (int) $id)->first();
        if ($existing) {
            $oldHash = hash('sha256', json_encode(json_decode($existing->raw_payload, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if ($oldHash !== $record->source_hash) {
                $this->issue($record, $config['id'], 'source_changed', 'Origem alterada: o cadastro existente foi preservado para revisão.', 'error');
                $record->update(['status' => 'conflict']);
                $this->stats[$source]['rejected']++;
            } else {
                $this->stats[$source]['skipped']++;
            }

            return;
        }
        $attributes = ['legacy_id' => (int) $id, 'name' => $name, 'name_key' => $nameKey,
            'raw_payload' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()];
        if (isset($config['document'])) {
            $document = Normalizer::document($raw[$config['document']] ?? '');
            $attributes += ['document' => $raw[$config['document']] ?? null, 'document_key' => strlen($document) <= 32 ? ($document ?: null) : null];
            if (! Normalizer::validDocument($document)) {
                $this->issue($record, $config['document'], 'invalid_document', 'Documento ausente ou inválido; não utilizado para associação automática.');
            }
        }
        DB::table($config['table'])->insert($attributes);
        $record->update(['status' => 'imported']);
        $this->stats[$source]['imported']++;
    }

    private function loadCatalogs(): void
    {
        foreach (self::CATALOGS as $config) {
            $this->catalogs[$config['table']] = ['name' => [], 'document' => []];
            foreach (DB::table($config['table'])->get() as $row) {
                $this->catalogs[$config['table']]['name'][$row->name_key][] = $row;
                if (isset($row->document_key) && Normalizer::validDocument($row->document_key)) {
                    $this->catalogs[$config['table']]['document'][$row->document_key][] = $row;
                }
            }
        }
    }

    private function resolveUserScopes(): void
    {
        foreach (AccessScope::all() as $scope) {
            $funds = $this->catalogs['contract_funds']['name'][Normalizer::name($scope->fund)] ?? [];
            $departments = $this->catalogs['contract_departments']['name'][Normalizer::name($scope->department)] ?? [];
            $scope->fund_id = count($funds) === 1 ? $funds[0]->id : null;
            $scope->department_id = count($departments) === 1 ? $departments[0]->id : null;
            $scope->save();
            if (! $scope->fund_id || ($scope->department && ! $scope->department_id)) {
                $this->issue(null, 'scope', 'unresolved_user_scope', 'Vínculo de usuário não conciliado com os catálogos; acesso aos contratos permanece bloqueado.', 'warning', (string) $scope->id);
            }
        }
    }

    private function contract(array $raw): string
    {
        if (array_diff(array_keys(ContractFields::MAP), array_keys($raw)) || count($raw) !== 80) {
            throw new RuntimeException('A estrutura de contratos não corresponde aos 80 campos mapeados.');
        }
        $id = (string) ($raw['ID_Contrato'] ?? 'missing');
        $record = $this->record('TB_Contratos', $id, $raw);
        if (! $this->validId($id)) {
            $this->issue($record, 'ID_Contrato', 'invalid_id', 'Identificador de contrato inválido.', 'error');
            $record->update(['status' => 'rejected']);

            return 'rejected';
        }
        $existing = Contract::withTrashed()->where('legacy_id', (int) $id)->first();
        if ($existing) {
            if ($existing->legacy_record_id === $record->id) {
                return 'skipped';
            }
            $this->issue($record, 'ID_Contrato', 'source_changed', 'Dados de origem alterados: o contrato existente não foi sobrescrito.', 'error');
            $record->update(['status' => 'conflict']);

            return 'rejected';
        }
        $attributes = [];
        $quality = [];
        foreach (ContractFields::MAP as $source => $definition) {
            $value = $raw[$source];
            if ($definition['type'] === 'date') {
                $normalized = Normalizer::date($value);
                if (trim($value ?? '') !== '' && $normalized === null) {
                    $quality[] = $this->issue($record, $source, $value === '0000-00-00' ? 'zero_date' : 'invalid_date', 'Data ausente ou inválida na origem; convertida para não informada.');
                }
                $value = $normalized;
            } elseif ($definition['type'] === 'money') {
                $normalized = Normalizer::money($value);
                if (trim($value ?? '') !== '' && $normalized === null) {
                    $quality[] = $this->issue($record, $source, 'invalid_money', 'Valor monetário inválido; preservado na origem e não convertido.');
                }
                $value = $normalized;
            }
            $attributes[$definition['column']] = $value;
        }
        if (! trim($raw['Contrato'] ?? '')) {
            $quality[] = $this->issue($record, 'Contrato', 'missing_number', 'Contrato sem número informado.');
        }
        $fund = $this->fund($raw, $record, $quality);
        $departmentName = Normalizer::name($raw['Secretaria']);
        $departments = $this->catalogs['contract_departments']['name'][$departmentName] ?? [];
        $department = count($departments) === 1 ? $departments[0] : null;
        if ($departmentName !== '' && ! $department) {
            $quality[] = $this->issue($record, 'Secretaria', 'unresolved_department', 'Secretaria não conciliada; contrato visível somente a administradores.');
        }
        $document = Normalizer::document($raw['CPF_CNPJ']);
        $suppliers = Normalizer::validDocument($document) ? ($this->catalogs['suppliers']['document'][$document] ?? []) : [];
        $supplier = count($suppliers) === 1 ? $suppliers[0] : null;
        if (! $supplier) {
            $quality[] = $this->issue($record, 'CPF_CNPJ', count($suppliers) > 1 ? 'ambiguous_supplier' : 'unresolved_supplier', 'Fornecedor não conciliado de forma única; dados históricos preservados no contrato.');
        }
        $end = $attributes['vigencia_fim_atual'] ?? $attributes['vigencia_fim_original'];
        $start = $attributes['vigencia_inicio'];
        $review = trim($raw['N_Aditivo'] ?? '') !== '' && trim($raw['N_Aditivo']) !== '0';
        $review = $review || (trim($raw['Proc_Aditivo'] ?? '') !== '' && trim($raw['Proc_Aditivo']) !== '0');
        if ($review) {
            $quality[] = $this->issue($record, 'N_Aditivo', 'amendments_pending', 'Aditivos ainda não conciliados; situação de vigência em revisão.');
        }
        if ($start && $end && $end < $start) {
            $review = true;
            $quality[] = $this->issue($record, 'Final_Vig_Atualiz', 'invalid_chronology', 'Término anterior ao início da vigência.');
        }
        if (! $start || ! $end) {
            $quality[] = $this->issue($record, 'Vigencia', 'missing_validity', 'Vigência sem datas suficientes para determinar a situação.');
        }
        $year = null;
        if (preg_match('~/([0-9]{2}|[0-9]{4})$~D', trim($raw['Contrato'] ?? ''), $match)) {
            $year = strlen($match[1]) === 2 ? 2000 + (int) $match[1] : (int) $match[1];
        } elseif ($attributes['data_contrato']) {
            $year = (int) substr($attributes['data_contrato'], 0, 4);
        }
        Contract::create($attributes + ['legacy_record_id' => $record->id, 'fund_id' => $fund?->id, 'department_id' => $department?->id,
            'supplier_id' => $supplier?->id, 'supplier_document_key' => strlen($document) <= 32 ? ($document ?: null) : null,
            'ownership_resolved' => $fund !== null && ($departmentName === '' || $department !== null),
            'vigencia_em_revisao' => $review, 'exercicio' => $year, 'quality_issues' => $quality]);
        $record->update(['status' => 'imported']);

        return 'imported';
    }

    private function fund(array $raw, LegacyRecord $record, array &$quality): ?object
    {
        $key = Normalizer::name($raw['Fundo']);
        $byName = $this->catalogs['contract_funds']['name'][$key] ?? [];
        $document = Normalizer::document($raw['CNPJ_Fundo']);
        $byDocument = Normalizer::validDocument($document) ? ($this->catalogs['contract_funds']['document'][$document] ?? []) : [];
        if (count($byDocument) === 1 && count($byName) === 1 && $byDocument[0]->id === $byName[0]->id) {
            return $byDocument[0];
        }
        if (count($byName) === 1 && ! Normalizer::validDocument($document)) {
            $quality[] = $this->issue($record, 'CNPJ_Fundo', 'fund_by_name', 'Documento do fundo inválido ou ausente; vínculo obtido por nome exato normalizado.');

            return $byName[0];
        }
        $quality[] = $this->issue($record, 'Fundo', 'unresolved_fund', 'Fundo sem correspondência única e consistente; contrato visível somente a administradores.');

        return null;
    }
}
