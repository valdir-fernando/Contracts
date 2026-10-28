<?php

namespace App\Console\Commands;

use App\Infrastructure\Contracts\ReconcileContractAccumulatedValues as Reconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ReconcileContractAccumulatedValues extends Command
{
    protected $signature = 'contracts:reconcile-accumulated-values
        {--apply : Corrigir divergências; sem esta opção apenas simula}
        {--json : Exibir resultado e pendências em JSON}';

    protected $description = 'Confere valor acumulado = valor original + soma dos aditivos, com versão e auditoria';

    public function handle(Reconciliation $reconciliation): int
    {
        $lock = Cache::lock('contracts-reference-import', 1800);
        if (! $lock->get()) {
            $this->error('Já existe uma manutenção/importação em andamento.');

            return self::FAILURE;
        }
        try {
            $result = $reconciliation->run(! $this->option('apply'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info($this->option('apply') ? 'Conciliação aplicada.' : 'Simulação; nenhuma alteração persistida.');
                $this->table(['Conferidos', 'Corretos', 'Divergentes', 'Atualizados', 'Pendentes'], [
                    array_map(fn ($key) => $result[$key], ['checked', 'ok', 'divergent', 'updated', 'pending']),
                ]);
                if ($result['issues'] !== []) {
                    $this->table(['ID do contrato', 'Contrato', 'Pendência'], array_map('array_values', $result['issues']));
                }
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
