<?php

namespace App\Infrastructure\Documents;

use App\Services\Legacy\Normalizer;

final class ServiceOrderTemplate
{
    public function model(?string $type): string
    {
        return match (Normalizer::name($type ?? '')) {
            'OBRAS' => 'works',
            'SERVICO', 'SERVICOS', 'SERVICOS DIVERSOS' => 'services',
            default => 'generic',
        };
    }

    public function hash(string $model): string
    {
        $this->assertModel($model);

        return hash('sha256', file_get_contents(resource_path('views/documents/service-orders/layout.blade.php'))
            .file_get_contents(resource_path('views/documents/service-orders/'.$model.'.blade.php'))
            .file_get_contents(resource_path('documents/branding/prefeitura.png')));
    }

    public function render(array $snapshot): string
    {
        $this->assertModel($snapshot['model']);

        return view('documents.service-orders.'.$snapshot['model'], ['document' => $snapshot,
            'logo' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('documents/branding/prefeitura.png')))])->render();
    }

    private function assertModel(string $model): void
    {
        if (! in_array($model, ['works', 'services', 'generic'], true)) {
            throw new \InvalidArgumentException('Modelo de OS inválido.');
        }
    }
}
