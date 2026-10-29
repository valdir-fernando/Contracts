<?php

namespace App\Console\Commands;

use App\Infrastructure\Dfd\DfdLegacyImport;
use Illuminate\Console\Command;

class ImportDfd extends Command
{
    protected $signature = 'contracts:import-dfd {file? : Dump SQL de referência} {--apply : Aplicar importação auditada; sem a opção, apenas contar a origem} {--catalog-only : Importar somente o catálogo Prodata, sem DFDs ou produtos de DFD}';

    protected $description = 'Importa DFDs, produtos, contadores anuais e catálogo Prodata sem sobrescrever registros existentes';

    public function handle(DfdLegacyImport $importer): int
    {
        try {
            $counts = $importer->run($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'), (bool) $this->option('apply'), (bool) $this->option('catalog-only'));
        } catch (\Throwable $error) {
            report($error);
            $this->error('Não foi possível importar DFDs. Confira a estrutura e o arquivo. Nenhuma alteração foi aplicada.');

            return self::FAILURE;
        }
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
