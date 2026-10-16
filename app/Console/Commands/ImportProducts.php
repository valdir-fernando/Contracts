<?php

namespace App\Console\Commands;

use App\Services\Legacy\ProductImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportProducts extends Command
{
    protected $signature = 'contracts:import-products {file? : Dump SQL contendo tb_Produtos}';

    protected $description = 'Importa somente tb_Produtos, preservando produtos e saldos existentes';

    public function handle(ProductImporter $importer): int
    {
        try {
            $counts = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'));
        } catch (Throwable) {
            $this->error('Não foi possível importar tb_Produtos. Confira o arquivo e o schema. A transação foi revertida.');

            return self::FAILURE;
        }
        $this->info('Produtos: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
