<?php

namespace App\Console\Commands;

use App\Infrastructure\Contracts\CorrectHistoricalContractTypes as Correction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CorrectHistoricalContractTypes extends Command
{
    protected $signature = 'contracts:correct-historical-types {--apply : Persistir as correções; sem esta opção apenas consulta}';

    protected $description = 'Corrige somente grafias históricas confirmadas, com versão e auditoria de sistema';

    public function handle(Correction $correction): int
    {
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma manutenção/importação em andamento.');

            return self::FAILURE;
        }
        try {
            $apply = (bool) $this->option('apply');
            $count = $correction->execute(! $apply);
            $this->info(($apply ? 'Corrigidos: ' : 'A corrigir: ').$count);

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
