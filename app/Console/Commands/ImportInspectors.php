<?php

namespace App\Console\Commands;

use App\Services\Legacy\InspectorImporter;
use Illuminate\Console\Command;

class ImportInspectors extends Command
{
    protected $signature = 'contracts:import-inspectors {file? : Dump SQL contendo tb_Fiscal}';

    protected $description = 'Importa fiscais legados preservando contratos e cadastros existentes';

    public function handle(InspectorImporter $importer): int
    {
        try {
            $counts = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'));
        } catch (\Throwable) {
            $this->error('Não foi possível importar tb_Fiscal. Confira o arquivo e o schema. Nenhuma alteração foi aplicada.');

            return self::FAILURE;
        }
        $this->info('Fiscais: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
