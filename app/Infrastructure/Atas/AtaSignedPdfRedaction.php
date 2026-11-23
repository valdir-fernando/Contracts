<?php

namespace App\Infrastructure\Atas;

use FPDF;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AtaSignedPdfRedaction
{
    private const MAX_PAGES = 24;

    private const MAX_COPY_BYTES = 16 * 1024 * 1024;

    public function preview(string $original): array
    {
        return $this->withPages($original, static fn (array $pages) => array_map(
            static fn (string $page) => 'data:image/jpeg;base64,'.base64_encode(file_get_contents($page)), $pages
        ), preview: true);
    }

    public function validate(string $original): void
    {
        $this->withSource($original, $this->pageCount(...));
    }

    public function redact(string $original, array $rectangles, int $reviewedPages): string
    {
        return $this->withPages($original, function (array $pages) use ($rectangles, $reviewedPages) {
            if (count($pages) !== $reviewedPages) {
                throw ValidationException::withMessages(['pdf' => 'Revise todas as páginas antes de gravar.']);
            }
            foreach ($rectangles as $rectangle) {
                if (! isset($pages[$rectangle['page'] - 1])
                    || $rectangle['left'] >= $rectangle['right'] || $rectangle['top'] >= $rectangle['bottom']) {
                    throw ValidationException::withMessages(['pdf' => 'Há uma tarja inválida. Revise as marcações.']);
                }
            }
            $byPage = array_reduce($rectangles, static function (array $grouped, array $rectangle): array {
                $grouped[$rectangle['page']][] = $rectangle;

                return $grouped;
            }, []);
            $pdf = new FPDF;
            $pageBudget = intdiv(self::MAX_COPY_BYTES - 65536, count($pages));
            $imageBytes = 0;
            foreach ($pages as $index => $page) {
                $image = imagecreatefrompng($page);
                if ($image === false) {
                    throw new RuntimeException('Falha ao ler a imagem da página.');
                }
                $width = imagesx($image);
                $height = imagesy($image);
                $black = imagecolorallocate($image, 0, 0, 0);
                foreach ($byPage[$index + 1] ?? [] as $box) {
                    imagefilledrectangle($image, (int) floor($box['left'] * $width), (int) floor($box['top'] * $height),
                        (int) ceil($box['right'] * $width) - 1, (int) ceil($box['bottom'] * $height) - 1, $black);
                }
                $flattened = $page.'.redacted.png';
                if (! imagepng($image, $flattened, 6)) {
                    throw new RuntimeException('Falha ao gravar a imagem tarjada.');
                }
                if (filesize($flattened) > $pageBudget) {
                    $flattened = $page.'.redacted.jpg';
                    if (! imagejpeg($image, $flattened, 96)) {
                        throw new RuntimeException('Falha ao compactar a imagem tarjada.');
                    }
                }
                unset($image);
                $imageBytes += filesize($flattened);
                $this->checkCopySize($imageBytes);
                $scale = 297 / max($width, $height);
                $size = [$width * $scale, $height * $scale];
                $pdf->AddPage($width > $height ? 'L' : 'P', $size);
                $pdf->Image($flattened, 0, 0, ...$size);
            }
            $bytes = $pdf->Output('S');
            $this->checkCopySize(strlen($bytes));

            return $bytes;
        });
    }

    private function checkCopySize(int $bytes): void
    {
        if ($bytes > self::MAX_COPY_BYTES) {
            throw ValidationException::withMessages(['pdf' => 'A cópia processada excedeu 16 MB. Use um PDF menor.']);
        }
    }

    private function withPages(string $original, callable $consume, bool $preview = false): mixed
    {
        return $this->withSource($original, function (string $source) use ($consume, $preview) {
            $count = $this->pageCount($source);
            $directory = dirname($source);
            $options = $preview ? ['-scale-to', '1800', '-jpeg', '-jpegopt', 'quality=88'] : ['-scale-to', '3508', '-png'];
            $extension = $preview ? 'jpg' : 'png';
            $render = new Process(['pdftoppm', ...$options, $source, $directory.'/page'], timeout: 35);
            $render->run();
            $pages = glob($directory.'/page-*.'.$extension);
            if (! $render->isSuccessful() || count($pages) !== $count) {
                throw ValidationException::withMessages(['pdf' => 'Não foi possível processar todas as páginas do PDF.']);
            }
            natsort($pages);

            return $consume(array_values($pages));
        });
    }

    private function pageCount(string $source): int
    {
        $info = new Process(['pdfinfo', $source], env: ['LC_ALL' => 'C'], timeout: 8);
        $info->run();
        if (! $info->isSuccessful() || ! preg_match('/^Pages:\s+([1-9]\d*)$/m', $info->getOutput(), $match)
            || (int) $match[1] > self::MAX_PAGES || ! preg_match('/^Encrypted:\s+no$/m', $info->getOutput())) {
            throw ValidationException::withMessages(['pdf' => 'Selecione um PDF legível, sem senha, com até 24 páginas.']);
        }

        return (int) $match[1];
    }

    private function withSource(string $original, callable $consume): mixed
    {
        $directory = sys_get_temp_dir().'/ata-signed-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Falha ao preparar processamento do PDF.');
        }
        try {
            $source = $directory.'/source.pdf';
            if (file_put_contents($source, $original) !== strlen($original)) {
                throw new RuntimeException('Falha ao preparar o PDF.');
            }

            return $consume($source);
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
