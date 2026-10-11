<?php

namespace App\Console\Commands;

use App\Services\Legacy\HistoryImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ImportHistory extends Command
{
    protected $signature = 'contracts:import-history {path} {--dry-run}';

    protected $description = 'Importa aditivos e itens históricos sem aplicar valores ou prazos automaticamente';

    public function handle(HistoryImporter $importer): int
    {
        $path = $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Arquivo de referência não encontrado.');

            return self::FAILURE;
        }
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação em andamento.');

            return self::FAILURE;
        }
        try {
            $this->table(['Indicador', 'Quantidade'], collect($importer->import($path, $this->option('dry-run')))->map(fn ($value, $key) => [$key, $value])->values()->all());

            return self::SUCCESS;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
