<?php

namespace App\Infrastructure\ContractStewards;

use App\Models\ActivityEvent;
use App\Models\ImportRun;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CopyFundManager
{
    public function __construct(private StewardLegacyTable $legacy) {}

    public function copy(string $name, string $fundName): array
    {
        return DB::transaction(function () use ($name, $fundName) {
            $funds = DB::table('contract_funds')->whereNull('deleted_at')->where('name_key', Normalizer::name($fundName))
                ->orderBy('id')->lockForUpdate()->get();
            if ($funds->count() !== 1) {
                throw ValidationException::withMessages(['fund' => 'Selecione um único Fundo ativo cadastrado.']);
            }
            $fund = $funds->first();
            $sources = DB::table('tb_Gestor')->whereNull('deleted_at')->where('name_key', Normalizer::name($name))
                ->where('fund_name_key', $fund->name_key)->orderBy('id')->lockForUpdate()->get();
            if ($sources->count() !== 1) {
                throw ValidationException::withMessages(['name' => 'Não foi encontrado um único gestor do Fundo ativo com esse nome.']);
            }
            $source = $sources->first();
            $raw = json_decode($source->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $cpf = Normalizer::document($raw['CPF_Gestor'] ?? '');
            if (strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                throw ValidationException::withMessages(['cpf' => 'Corrija o CPF no cadastro de origem antes de copiar.']);
            }
            $column = DB::connection()->getQueryGrammar()->wrap('raw_payload->CPF_Gestor');
            $matches = DB::table('contract_stewards')->where('fund_name_key', $fund->name_key)
                ->where(function ($query) use ($source, $column, $cpf) {
                    $query->where('name_key', $source->name_key)
                        ->orWhereRaw("replace(replace(replace({$column}, '.', ''), '-', ''), ' ', '') = ?", [$cpf]);
                })->orderBy('id')->lockForUpdate()->get();
            if ($matches->count() > 1 || ($matches->count() === 1 && ($matches->first()->deleted_at !== null
                || Normalizer::document(json_decode($matches->first()->raw_payload, true)['CPF_Gestor'] ?? '') !== $cpf))) {
                throw ValidationException::withMessages(['name' => 'Há cadastro divergente ou excluído para esta pessoa. Revise a ficha antes de copiar.']);
            }
            if ($matches->count() === 1) {
                $record = $matches->first();

                return ['id' => $record->id, 'table_id' => DB::table('tb_Gestor_Cont')->where('contract_steward_id', $record->id)->value('ID_Gestor_Cont'), 'created' => false];
            }
            (new StewardAssignments)->assertAvailable($fund, null);
            $payload = array_intersect_key($raw, array_flip(['CPF_Gestor', 'RG_Gestor', 'Nome_Gestor', 'Data_Nasc', 'Sexo',
                'Email', 'Decreto', 'Data_Decreto', 'WhatsApp', 'Endereco', 'Cod_Prodata']));
            $payload['CPF_Gestor'] = $cpf;
            $payload['Nome_Gestor'] = mb_strtoupper($source->name, 'UTF-8');
            $payload['Fundo'] = $fund->name;
            $payload['Secretaria'] = null;
            $payload['Celular'] = $raw['Fone_Celular'] ?? null;
            $payload['Situacao'] = 'Ativo';
            foreach (['Data_Nasc', 'Data_Decreto'] as $field) {
                if (isset($payload[$field])) {
                    $payload[$field] = ContractStewardRegistration::dateForForm($payload[$field]) ?? $payload[$field];
                }
            }
            $run = ImportRun::create(['source_hash' => hash('sha256', $source->id.'|'.$fund->id.'|'.$source->raw_payload),
                'status' => 'running', 'started_at' => now()]);
            $id = DB::table('contract_stewards')->insertGetId(['name' => $payload['Nome_Gestor'], 'name_key' => Normalizer::name($source->name),
                'fund_name_key' => $fund->name_key, 'department_name_key' => '', 'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now()]);
            $tableId = $this->legacy->sync(DB::table('contract_stewards')->find($id));
            $reason = 'Cadastro de Gestor de Contratos a partir de tb_Gestor, execução '.$run->id;
            ActivityEvent::create(['actor_id' => null, 'action' => 'contract_steward_imported',
                'metadata' => ['id' => $id, 'gestor_cont_id' => $tableId, 'source_table' => 'tb_Gestor', 'source_record_id' => $source->id,
                    'source_legacy_id' => $source->legacy_id, 'fund_id' => $fund->id, 'import_run_id' => $run->id, 'reason' => $reason], 'created_at' => now()]);
            $run->update(['status' => 'completed', 'finished_at' => now(), 'source_count' => 1, 'imported_count' => 1,
                'skipped_count' => 0, 'rejected_count' => 0, 'summary' => ['catalog' => 'tb_Gestor_Cont', 'source' => 'tb_Gestor', 'record_id' => $id]]);

            return ['id' => $id, 'table_id' => $tableId, 'created' => true];
        }, 3);
    }
}
