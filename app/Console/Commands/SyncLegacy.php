<?php

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Services\Legacy\ContractImporter;
use App\Services\Legacy\HistoryImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncLegacy extends Command
{
    protected $signature = 'contracts:sync-legacy {--file= : Snapshot SQL privado} {--dry-run : Simular sem persistir}';

    protected $description = 'Importa snapshots novos de forma atômica; preserva alterações locais e aponta conflitos';

    public function handle(ContractImporter $contracts, HistoryImporter $history): int
    {
        $path = $this->option('file') ?: config('legacy.sync_path');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            $this->error('Configure um snapshot SQL privado legível ou informe --file.');

            return self::FAILURE;
        }
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação em andamento.');

            return self::FAILURE;
        }
        try {
            $hash = hash_file('sha256', $path);
            if (ActivityEvent::where('action', 'legacy_sync_completed')->where('metadata->source_hash', $hash)->exists()) {
                $this->info('Snapshot já processado; nenhuma alteração.');

                return self::SUCCESS;
            }
            DB::beginTransaction();
            try {
                $run = $contracts->import($path);
                $counts = $history->import($path);
                if (hash_file('sha256', $path) !== $hash) {
                    throw new RuntimeException('O snapshot mudou durante a leitura. Operação revertida.');
                }
                ActivityEvent::create(['action' => 'legacy_sync_completed', 'metadata' => ['source_hash' => $hash,
                    'contracts_imported' => $run->imported_count, 'contracts_conflicts' => $run->rejected_count,
                    'history' => $counts], 'created_at' => now()]);
                $this->option('dry-run') ? DB::rollBack() : DB::commit();
            } catch (\Throwable $error) {
                DB::rollBack();
                throw $error;
            }
            $this->info($this->option('dry-run') ? 'Simulação concluída; nenhuma alteração persistida.' : 'Snapshot processado. Consulte conflitos e pendências em Auditoria e conciliação.');

            return self::SUCCESS;
        } catch (\Throwable) {
            ActivityEvent::create(['action' => 'legacy_sync_failed', 'metadata' => ['reason' => 'Operação revertida integralmente; confira o snapshot privado.'], 'created_at' => now()]);
            $this->error('Sincronização interrompida; nenhuma alteração aplicada. Confira o snapshot e os registros de importação.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
