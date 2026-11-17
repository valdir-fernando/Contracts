<?php

namespace App\Infrastructure\Atas;

final class AtaExtractTemplate
{
    public function fingerprint(): string
    {
        return hash('sha256', implode('', array_map(fn ($path) => hash_file('sha256', $path), $this->paths())));
    }

    public function render(array $fields): string
    {
        [, $header, $footer] = $this->paths();

        return view('atas.extract', ['fields' => $fields,
            'header' => 'data:image/png;base64,'.base64_encode(file_get_contents($header)),
            'footer' => 'data:image/png;base64,'.base64_encode(file_get_contents($footer)),
        ])->render();
    }

    private function paths(): array
    {
        return [resource_path('views/atas/extract.blade.php'), resource_path('documents/atas/extract-header.png'),
            resource_path('documents/atas/extract-footer.png')];
    }
}
