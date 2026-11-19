<?php

namespace App\Infrastructure\Atas;

class AtaWithAdmissionWord extends AtaWithAdmissionPdf
{
    protected function auditAction(): string
    {
        return 'ata_with_admission_word_generated';
    }

    protected function render(string $html): string
    {
        return (new AtaWordDocument)->render($html);
    }
}
