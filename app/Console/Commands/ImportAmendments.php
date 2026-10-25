<?php

namespace App\Console\Commands;

use App\Infrastructure\Contracts\ImportHistoricalAmendments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ImportAmendments extends Command
{
    protected $signature = 'contracts:import-amendments {--apply : Persistir; sem esta opção apenas simula}';

    protected $description = 'Copia aditivos históricos vinculados para contract_amendments sem recalcular contratos';

    public function handle(ImportHistoricalAmendments $importer): int
    {
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação em andamento.');

            return self::FAILURE;
        }
        try {
            $counts = $importer->run(! $this->option('apply'));
            $this->info(($this->option('apply') ? 'Importação: ' : 'Simulação: ').json_encode($counts, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
