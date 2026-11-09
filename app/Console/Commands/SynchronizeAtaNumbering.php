<?php

namespace App\Console\Commands;

use App\Infrastructure\Atas\AtaNumbering;
use Illuminate\Console\Command;
use Throwable;

class SynchronizeAtaNumbering extends Command
{
    protected $signature = 'atas:sync-numbering {--apply : Atualizar os contadores anuais com auditoria}';

    protected $description = 'Confere a numeração anual única das atas, incluindo o histórico de todos os Fundos';

    public function handle(AtaNumbering $numbering): int
    {
        try {
            $this->info(json_encode($numbering->synchronizeHistory((bool) $this->option('apply')), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Sincronização não aplicada/revertida: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
