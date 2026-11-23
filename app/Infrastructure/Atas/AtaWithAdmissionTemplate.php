<?php

namespace App\Infrastructure\Atas;

final class AtaWithAdmissionTemplate extends AtaExtractTemplate
{
    private const VIEW = 'views/atas/with-admission.blade.php';

    public const PRODUCTS_VIEW = 'views/atas/document-products.blade.php';

    private const HEADER = 'documents/atas/with-admission-header.png';

    private const FOOTER = 'documents/atas/with-admission-footer.png';

    public function fingerprint(): string
    {
        return hash('sha256', implode('', array_map(
            fn ($path) => hash_file('sha256', resource_path($path)),
            [self::VIEW, self::HEADER, self::FOOTER, self::PRODUCTS_VIEW],
        )));
    }

    public function render(array $fields): string
    {
        $image = static fn ($path) => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path($path)));

        return view('atas.with-admission', [
            'fields' => $fields, 'header' => $image(self::HEADER), 'footer' => $image(self::FOOTER),
        ])->render();
    }
}
