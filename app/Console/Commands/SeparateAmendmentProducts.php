<?php

namespace App\Console\Commands;

use App\Infrastructure\Contracts\SeparateAmendmentProducts as Separator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SeparateAmendmentProducts extends Command
{
    protected $signature = 'contracts:separate-amendment-products {--apply : Persistir a separação; sem esta opção apenas simula}';

    protected $description = 'Separa produtos de aditivos preservando origem, auditoria, valores e saldos';

    public function handle(Separator $separator): int
    {
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma importação ou separação em andamento.');

            return self::FAILURE;
        }
        try {
            $counts = $separator->run(! $this->option('apply'));
            $this->info(($this->option('apply') ? 'Separação: ' : 'Simulação: ').json_encode($counts, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
