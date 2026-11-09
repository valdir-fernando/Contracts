<?php

namespace App\Console\Commands;

use App\Infrastructure\Atas\ImportAtaAmendmentProducts as Importer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportAtaAmendmentProducts extends Command
{
    protected $signature = 'atas:import-amendment-products {directory?} {--apply : Gravar os produtos conferidos}';

    protected $description = 'Importa produtos discriminados nos aditivos, preservando os itens originais';

    public function handle(Importer $importer): int
    {
        $lock = Cache::lock('atas-reference-import', 7200);
        if (! $lock->get()) {
            $this->error('Já existe manutenção de atas em andamento.');

            return self::FAILURE;
        }
        try {
            $result = $importer->run($this->argument('directory') ?? base_path('Reference/ATAS/ADITIVOS_ATAS'), (bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['pending'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            report($error);
            $this->error('Importação não aplicada/revertida: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
