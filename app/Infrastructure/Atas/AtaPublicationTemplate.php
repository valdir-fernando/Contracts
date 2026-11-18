<?php

namespace App\Infrastructure\Atas;

final class AtaPublicationTemplate extends AtaExtractTemplate
{
    public function fingerprint(): string
    {
        return hash_file('sha256', resource_path('views/atas/publication.blade.php'));
    }

    public function render(array $fields): string
    {
        return view('atas.publication', ['fields' => $fields])->render();
    }
}
