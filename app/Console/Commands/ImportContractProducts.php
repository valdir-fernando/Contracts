<?php

namespace App\Console\Commands;

use App\Services\Legacy\ContractProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportContractProducts extends Command
{
    protected $signature = 'contracts:import-contract-products {file? : Dump SQL do modelo} {--dry-run : Simular sem persistir}';

    protected $description = 'Importa produtos legados com vínculo confirmado, sem alterar saldos ou totais';

    public function handle(ContractProductImporter $importer): int
    {
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação em andamento.');

            return self::FAILURE;
        }
        try {
            $counts = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'), (bool) $this->option('dry-run'));
            $this->info(($this->option('dry-run') ? 'Simulação: ' : 'Importação: ').json_encode($counts, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Importação revertida. Confira arquivo, schema e vínculos da origem.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
