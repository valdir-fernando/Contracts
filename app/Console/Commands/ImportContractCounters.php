<?php

namespace App\Console\Commands;

use App\Services\Legacy\ContractCounterImporter;
use Illuminate\Console\Command;

class ImportContractCounters extends Command
{
    protected $signature = 'contracts:import-contract-counters {year : Exercício dos contadores legados} {file? : Dump SQL} {--dry-run : Conferir sem persistir alterações}';

    protected $description = 'Inicializa sequências por Fundo/ano sem reduzir contadores ou alterar contratos';

    public function handle(ContractCounterImporter $importer): int
    {
        $year = (string) $this->argument('year');
        if (! preg_match('/^[0-9]{4}$/D', $year)) {
            $this->error('Informe o ano dos contadores com quatro dígitos.');

            return self::FAILURE;
        }
        try {
            $summary = $importer->import($this->argument('file') ?? base_path('database-example/u910323952_bdgestao_niq.sql'),
                (int) $year, (bool) $this->option('dry-run'));
        } catch (\Throwable) {
            $this->error('Importação recusada. Confira o arquivo, os números e os vínculos únicos dos Fundos. Nenhuma alteração foi aplicada.');

            return self::FAILURE;
        }
        $this->table(['Fundo (ID)', 'Ano', 'Origem', 'Maior utilizado', 'Contador anterior', 'Último número'], $summary);
        $this->info($this->option('dry-run') ? 'Conferência concluída; nenhuma alteração persistida.' : 'Contadores importados. Contratos existentes preservados.');

        return self::SUCCESS;
    }
}
