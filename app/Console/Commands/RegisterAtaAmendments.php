<?php

namespace App\Console\Commands;

use App\Infrastructure\Atas\RegisterAtaAmendments as Register;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RegisterAtaAmendments extends Command
{
    protected $signature = 'atas:register-amendments {review : JSON privado de conferência documental por SHA-256} {directory?} {--apply : Gravar cadastros e PDFs conferidos}';

    protected $description = 'Cadastra aditivos e anexa somente PDFs conferidos com assinatura das duas partes';

    public function handle(Register $register): int
    {
        $lock = Cache::lock('atas-reference-import', 7200);
        if (! $lock->get()) {
            $this->error('Já existe manutenção de atas em andamento.');

            return self::FAILURE;
        }
        try {
            $review = json_decode(file_get_contents($this->argument('review')), true, flags: JSON_THROW_ON_ERROR);
            $result = $register->run($this->argument('directory') ?? base_path('Reference/ATAS/ADITIVOS_ATAS'), $review, (bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Cadastro não aplicado/revertido: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
