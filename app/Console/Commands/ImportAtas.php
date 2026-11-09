<?php

namespace App\Console\Commands;

use App\Infrastructure\Atas\AtaReferenceImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportAtas extends Command
{
    protected $signature = 'atas:import-reference {directory? : Pasta com subpastas PE_* e BD_ATAS.xlsx nela ou no pai imediato} {--apply : Persistir a carga conferida}';

    protected $description = 'Confere e importa atas, órgãos e produtos de documentos Word/PDF, sem sobrescrever dados';

    public function handle(AtaReferenceImport $importer): int
    {
        $lock = Cache::lock('atas-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação de atas em andamento.');

            return self::FAILURE;
        }
        try {
            $result = $importer->run($this->argument('directory') ?? base_path('Reference/ATAS'), (bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Importação não aplicada/revertida: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
