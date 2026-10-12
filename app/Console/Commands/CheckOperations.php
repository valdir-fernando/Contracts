<?php

namespace App\Console\Commands;

use App\Infrastructure\Operations\ProcessHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckOperations extends Command
{
    protected $signature = 'contracts:check-operations {--process= : Verificar somente worker ou scheduler}';

    protected $description = 'Verifica banco, fila e atividade recente de worker/scheduler';

    public function handle(ProcessHeartbeat $heartbeat): int
    {
        $process = $this->option('process');
        if ($process !== null && ! in_array($process, ['worker', 'scheduler'], true)) {
            $this->error('Informe worker ou scheduler.');

            return self::FAILURE;
        }
        try {
            DB::select('SELECT 1');
            DB::table('jobs')->limit(1)->get(['id']);
            $this->info('Banco e tabela da fila acessíveis.');
            $healthy = true;
            foreach ($process === null ? ['worker', 'scheduler'] : [$process] as $name) {
                if ($heartbeat->healthy($name)) {
                    $this->info($name.': atividade recente confirmada.');
                } else {
                    $this->error($name.': sem atividade recente.');
                    $healthy = false;
                }
            }
            if ($process === null) {
                $failed = DB::table('failed_jobs')->count();
                $this->line('Jobs com falha registrados: '.$failed.'.');
                $waiting = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<', now()->subMinutes(5)->timestamp)->exists();
                if ($waiting) {
                    $this->error('Há jobs disponíveis aguardando processamento há mais de cinco minutos.');
                    $healthy = false;
                }
            }

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Banco ou infraestrutura da fila indisponível.');

            return self::FAILURE;
        }
    }
}
