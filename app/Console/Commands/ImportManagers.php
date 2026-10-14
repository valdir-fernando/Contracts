<?php

namespace App\Console\Commands;

use App\Services\Legacy\ManagerImporter;
use Illuminate\Console\Command;

class ImportManagers extends Command
{
    protected $signature = 'contracts:import-managers {file? : Dump SQL contendo tb_Gestor}';

    protected $description = 'Importa somente gestores legados, preservando contratos e cadastros existentes';

    public function handle(ManagerImporter $importer): int
    {
        try {
            $counts = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'));
        } catch (\Throwable) {
            $this->error('Não foi possível importar tb_Gestor. Confira o arquivo e o schema. Nenhuma alteração foi aplicada.');

            return self::FAILURE;
        }
        $this->info('Gestores: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
