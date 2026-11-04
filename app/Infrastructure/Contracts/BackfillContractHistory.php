<?php

namespace App\Infrastructure\Contracts;

use App\Models\ActivityEvent;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/** Explicit CLI maintenance; reads saved snapshots without impersonating a user. */
final class BackfillContractHistory
{
    public function run(int $year, bool $apply = false): array
    {
        return DB::transaction(function () use ($year, $apply) {
            $result = ['year' => $year, 'apply' => $apply, 'checked' => 0, 'existing' => 0, 'missing' => 0, 'inserted' => 0, 'invalid' => 0];
            $inserted = [];
            $contracts = Contract::withTrashed()->whereYear('data_contrato', $year)->orderBy('id')->lockForUpdate()->get();
            foreach ($contracts as $contract) {
                $result['checked']++;
                $entry = ContractHistoryEntry::fromContract($contract);
                if (trim($entry['Contrato']) === '' || trim($entry['Fundo']) === '') {
                    $result['invalid']++;

                    continue;
                }
                if (DB::table('tb_Historico')->where('Contrato', $entry['Contrato'])->where('Fundo', $entry['Fundo'])->exists()) {
                    $result['existing']++;

                    continue;
                }
                $result['missing']++;
                if ($apply) {
                    $inserted[] = ['contract_id' => $contract->id, 'history_id' => DB::table('tb_Historico')->insertGetId($entry, 'ID')];
                    $result['inserted']++;
                }
            }
            if ($inserted !== []) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'contract_history_backfilled',
                    'metadata' => ['source' => 'authorized_cli', 'year' => $year, 'entries' => $inserted], 'created_at' => now()]);
            }

            return $result;
        });
    }
}
