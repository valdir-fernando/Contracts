<?php

namespace App\Console\Commands;

use App\Services\Legacy\FichaValuesImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportFichaValues extends Command
{
    protected $signature = 'contracts:import-ficha-values {file? : Dump SQL contendo tb_ValorFicha}';

    protected $description = 'Importa somente o catálogo tb_ValorFicha, preservando registros existentes';

    public function handle(FichaValuesImporter $importer): int
    {
        try {
            $counts = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'));
        } catch (Throwable) {
            $this->error('Não foi possível importar tb_ValorFicha. Confira o arquivo e o schema. A transação foi revertida.');

            return self::FAILURE;
        }
        $this->info('Valores por ficha: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
