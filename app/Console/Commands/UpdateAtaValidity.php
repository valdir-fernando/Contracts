<?php

namespace App\Console\Commands;

use App\Infrastructure\Atas\UpdateAtaValidity as UpdateValidity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class UpdateAtaValidity extends Command
{
    protected $signature = 'atas:update-validity {directory? : Pasta dos aditivos} {--apply : Gravar datas e aditivos com auditoria}';

    protected $description = 'Atualiza a vigência pela data da ata, final do aditivo ou período original';

    public function handle(UpdateValidity $service): int
    {
        $lock = Cache::lock('atas-reference-import', 3600);
        if (! $lock->get()) {
            $this->error('Já existe uma importação/atualização de atas em andamento.');

            return self::FAILURE;
        }
        try {
            $result = $service->run($this->argument('directory') ?? base_path('Reference/ATAS/ADITIVOS_ATAS'), (bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Atualização não aplicada/revertida: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
