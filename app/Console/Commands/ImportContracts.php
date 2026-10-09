<?php

namespace App\Console\Commands;

use App\Services\Legacy\ContractImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ImportContracts extends Command
{
    protected $signature = 'contracts:import {--file= : Caminho do dump SQL} {--dry-run : Simular sem persistir alterações}';

    protected $description = 'Importa contratos e catálogos legados com preservação da origem e relatório de qualidade';

    public function handle(ContractImporter $importer): int
    {
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação em andamento.');

            return self::FAILURE;
        }
        try {
            $run = $importer->import($this->option('file') ?: base_path('database-example/u910323952_bdgestao_niq.sql'), (bool) $this->option('dry-run'));
            $this->info($this->option('dry-run') ? 'Simulação concluída; nenhuma alteração persistida.' : 'Importação concluída.');
            $this->table(['Lidos', 'Importados', 'Já existentes', 'Rejeitados', 'Ocorrências'], [[$run->source_count, $run->imported_count, $run->skipped_count, $run->rejected_count, $run->issues_count]]);
            foreach ($run->summary['catalogs'] as $table => $stats) {
                $this->line($table.': '.$stats['source'].' lidos; '.$stats['imported'].' novos; '.$stats['skipped'].' existentes; '.$stats['rejected'].' rejeitados.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
