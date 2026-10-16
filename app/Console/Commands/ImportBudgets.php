<?php

namespace App\Console\Commands;

use App\Services\Legacy\BudgetImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportBudgets extends Command
{
    protected $signature = 'contracts:import-budgets {file? : Dump SQL contendo tb_Dotacao}';

    protected $description = 'Importa somente o catálogo tb_Dotacao, preservando registros existentes';

    public function handle(BudgetImporter $importer): int
    {
        try {
            $counts = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'));
        } catch (Throwable) {
            $this->error('Não foi possível importar tb_Dotacao. Confira o arquivo e o schema. A transação foi revertida.');

            return self::FAILURE;
        }
        $this->info('Dotações: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
