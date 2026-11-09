<?php

namespace App\Console\Commands;

use App\Infrastructure\Atas\ReconcileAtaProcessValidity as Reconcile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReconcileAtaProcessValidity extends Command
{
    protected $signature = 'atas:reconcile-process-validity {directory? : Pasta dos processos com cópias das atas} {--apply : Gravar vigências originais com auditoria}';

    protected $description = 'Confere a vigência das atas originais dentro dos processos, sem aplicar aditivos não assinados';

    public function handle(Reconcile $service): int
    {
        $lock = Cache::lock('atas-reference-import', 7200);
        if (! $lock->get()) {
            $this->error('Já existe manutenção de atas em andamento.');

            return self::FAILURE;
        }
        try {
            $result = $service->run($this->argument('directory') ?? base_path('Reference/ATAS/ADITIVOS_ATAS/Nova pasta'), (bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['pending'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            report($error);
            $this->error('Conferência não aplicada/revertida: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
