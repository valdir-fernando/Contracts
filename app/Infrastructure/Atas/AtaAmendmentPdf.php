<?php

namespace App\Infrastructure\Atas;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class AtaAmendmentPdf extends AtaAmendmentWord
{
    protected function auditAction(): string
    {
        return 'ata_amendment_pdf_generated';
    }

    protected function render(string $word): string
    {
        $directory = sys_get_temp_dir().'/ata-amendment-pdf-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Não foi possível preparar a emissão do PDF do aditivo.');
        }

        try {
            $source = $directory.'/aditivo.docx';
            $target = $directory.'/aditivo.pdf';
            if (file_put_contents($source, $word) !== strlen($word)) {
                throw new RuntimeException('Não foi possível preparar o modelo para a conversão em PDF.');
            }
            $profile = 'file://'.implode('/', array_map('rawurlencode', explode('/', $directory))).'/profile';
            $process = new Process(['libreoffice', '-env:UserInstallation='.$profile, '--headless', '--norestore',
                '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $directory, $source],
                env: ['XDG_CACHE_HOME' => $directory, 'GSETTINGS_BACKEND' => 'memory'], timeout: 25);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($target)) {
                throw new RuntimeException('Não foi possível converter o aditivo em PDF.');
            }
            $pdf = file_get_contents($target);
            if ($pdf === false || ! str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('A conversão não gerou um PDF válido.');
            }

            return $pdf;
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException('A conversão do aditivo em PDF excedeu o tempo limite.', previous: $exception);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
