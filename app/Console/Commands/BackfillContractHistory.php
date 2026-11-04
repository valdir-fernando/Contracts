<?php

namespace App\Console\Commands;

use App\Infrastructure\Contracts\BackfillContractHistory as Backfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class BackfillContractHistory extends Command
{
    protected $signature = 'contracts:backfill-history {year} {--apply}';

    protected $description = 'Preenche o histórico pelo ano da data do contrato; sem --apply apenas confere';

    public function handle(Backfill $backfill): int
    {
        $year = filter_var($this->argument('year'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 1900 || $year > 9999) {
            $this->error('Informe um ano válido.');

            return self::FAILURE;
        }
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma manutenção em andamento.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($backfill->run($year, (bool) $this->option('apply')), JSON_THROW_ON_ERROR));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
