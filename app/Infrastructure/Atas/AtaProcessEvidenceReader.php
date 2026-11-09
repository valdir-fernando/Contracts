<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaTerm;
use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Reads original ata copies inside administrative processes, never draft extensions. */
class AtaProcessEvidenceReader
{
    public function read(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Pasta de processos não encontrada.');
        }
        $paths = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (strtolower($file->getExtension()) === 'pdf' && preg_match('/^processo/ui', $file->getFilename())) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        $entries = [];
        $pending = [];
        foreach ($paths as $path) {
            try {
                $entries[] = $this->document($path);
            } catch (\Throwable $error) {
                $pending[] = ['file' => str_replace('\\', '/', substr($path, strlen($root) + 1)), 'reason' => $error->getMessage()];
            }
        }

        return ['entries' => $entries, 'pending' => $pending];
    }

    public function document(string $path): array
    {
        $hash = hash_file('sha256', $path);
        $cache = storage_path('app/private/ata-process-evidence/'.$hash);
        if (! is_dir($cache)) {
            mkdir($cache, 0700, true);
        }
        $reviewFiles = glob($cache.'/*.review.json');
        sort($reviewFiles);
        $reviewHashes = array_map(fn ($file) => hash_file('sha256', $file), $reviewFiles);
        $parsedCache = $cache.'/parsed-'.hash('sha256', hash_file('sha256', __FILE__).implode('|', $reviewHashes)).'.json';
        if (is_file($parsedCache)) {
            $entry = json_decode(file_get_contents($parsedCache), true, flags: JSON_THROW_ON_ERROR);
            $sourcePath = str_starts_with($path, base_path().DIRECTORY_SEPARATOR) ? substr($path, strlen(base_path()) + 1) : $path;
            $entry['source']['file'] = str_replace('\\', '/', $sourcePath);
            if (! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Processo alterado durante a leitura.');
            }

            return $entry;
        }
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(90)->mustRun();
        $pages = explode("\f", $process->getOutput());
        $groups = [];
        foreach ($pages as $index => $page) {
            $body = preg_replace('/N[ºo] Processo:[^\n]*/ui', '', $page);
            if ($index === count($pages) - 1 && trim($page) === '') {
                continue;
            }
            if (mb_strlen(trim($body)) < 700) {
                $last = array_key_last($groups);
                if ($last === null || end($groups[$last]) !== $index - 1) {
                    $groups[] = [];
                }
                $groups[array_key_last($groups)][] = $index;
            }
        }
        $usedOcr = [];
        foreach ($groups as $group) {
            foreach (array_unique([...array_slice($group, 0, 4), ...array_slice($group, -2)]) as $index) {
                $pages[$index] = $this->ocrPage($path, $index + 1, $cache);
                $usedOcr[] = $index + 1;
            }
        }
        try {
            $entry = $this->parse($pages, basename(dirname($path)));
        } catch (RuntimeException $initial) {
            // Long item tables can push the original validity clause into the middle.
            // Read the remaining scanned pages only when the initial selection is insufficient.
            foreach ($groups as $group) {
                foreach ($group as $index) {
                    if (! in_array($index + 1, $usedOcr, true)) {
                        $pages[$index] = $this->ocrPage($path, $index + 1, $cache);
                        $usedOcr[] = $index + 1;
                    }
                }
            }
            $entry = $this->parse($pages, basename(dirname($path)));
        }
        if (! hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('Processo alterado durante a leitura.');
        }
        $sourcePath = str_starts_with($path, base_path().DIRECTORY_SEPARATOR) ? substr($path, strlen(base_path()) + 1) : $path;
        $entry['source'] = ['file' => str_replace('\\', '/', $sourcePath), 'format' => 'pdf',
            'sha256' => $hash, 'size' => filesize($path), 'pages' => $entry['pages'], 'ocr_pages' => $usedOcr,
            'kind' => 'copia_ata_original_em_processo'];
        if ($reviewFiles !== []) {
            $entry['source']['ocr_reviews'] = array_map(function ($file) {
                $review = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

                return ['page' => (int) basename($file), 'original_text_sha256' => $review['original_text_sha256'],
                    'review_sha256' => hash_file('sha256', $file), 'reason' => $review['reason']];
            }, $reviewFiles);
        }
        file_put_contents($parsedCache, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($parsedCache, 0600);

        return $entry;
    }

    public function parse(array $pages, string $folder): array
    {
        $identity = new AtaAmendmentReader;
        preg_match('/ATA_(\d+)-(\d{2}|\d{4})\b/u', $folder, $expected);
        $expectedNumber = isset($expected[1]) ? $identity->key($expected[1].'/'.$expected[2]) : null;
        $start = null;
        $number = null;
        foreach ($pages as $index => $page) {
            if (preg_match('/(?:^|\n)\h*\|?\h*ATA\s+DE\s+REGISTRO\s+DE\s+PRE[ÇC]OS\s+N[º°o.]*\s*(\d+\s*\/\s*\d{4})/ui', $page, $match)) {
                $number = $identity->key($match[1]);
                if ($expectedNumber !== null && $number !== $expectedNumber) {
                    throw new RuntimeException('A cópia da ata diverge da identificação esperada.');
                }
                $start = $index;
                break;
            }
        }
        if ($start === null) {
            throw new RuntimeException('Cópia da ata original não identificada.');
        }
        $date = null;
        $end = null;
        // A request/cover or digital-signature timestamp never supplies DATA_ATA.
        for ($index = $start; $index < count($pages); $index++) {
            if ($index > $start && preg_match('/Andamento processual|DOCUMENTO DE FORMALIZAÇÃO DE DEMANDA|PARECER JUR[ÍI]DICO/ui', $pages[$index])) {
                break;
            }
            if (($date = $this->date($pages[$index])) !== null) {
                $end = $index;
                break;
            }
        }
        if ($end === null) {
            throw new RuntimeException('Data do fecho da ata original não identificada.');
        }
        $text = implode("\n", array_slice($pages, $start, $end - $start + 1));
        $flat = trim(preg_replace('/\s+/u', ' ', $text));
        $flat = preg_replace('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-)\s+(\d{2})/u', '$1$2', $flat);
        $process = $this->capture('/PROCESSO ADMINISTRATIVO\s+N[º°o.]*\s*(\d{8,})/ui', $flat);
        $procurement = $this->capture('/PREG[ÃA]O ELETR[ÔO]NICO\s*(?:N[º°o.]*\s*)?(\d+\s*\/\s*\d{4})/ui', $flat);
        $period = $this->capture('/A validade da Ata de Registro de Pre[çc]os ser[áa] de\s*(\d+\s*(?:\([^)]*\)\s*)?(?:anos?|meses|m[êe]s|dias?)\b)/ui', $flat);
        if ($process === null || $procurement === null || $period === null) {
            throw new RuntimeException('Processo original, pregão ou duração não reconhecido na cópia da ata.');
        }
        $supplier = $this->capture('/FORNECEDOR\s*:\s*(.*?)(?:A listagem do cadastro|ÓRGÃO\(S\))/ui', $flat);
        $supplierName = $supplier === null ? null : $this->capture('/^(.*?),\s*com sede/ui', $supplier);
        $supplierCnpj = $supplier === null ? null : $this->capture('/(\d{2}\.\d{3}\.\d{3}[\/.]\d{4}-\d{2})/u', $supplier);
        $manager = $this->capture('/\bO(?:\(A\))?\s+((?:FUNDO MUNICIPAL|MUNIC[IÍ]PIO).*?),\s*pessoa jur[íi]dica/ui', $flat);
        $intro = $this->capture('/pessoa jur[íi]dica(.*?)considerando o julgamento/ui', $flat);
        $managerCnpj = null;
        if (preg_match('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-)[^\d]{0,12}(\d{2})\b/u', $intro ?? '', $managerDocument)) {
            $managerCnpj = $managerDocument[1].$managerDocument[2];
        }
        if ($managerCnpj === null) {
            throw new RuntimeException('CNPJ do gerenciador não reconhecido.');
        }

        return ['ATA' => $number, 'DATA_ATA' => $date, 'PROCESSO' => $process, 'N_LICIT' => $identity->key($procurement),
            'PERIODO_ATA' => $period, 'FINAL_ATA' => AtaTerm::end(new DateTimeImmutable($date), $period)->format('Y-m-d'),
            'FORNECEDOR' => $supplierName, 'CNPJ_FORNECEDOR' => $supplierCnpj === null ? null : preg_replace('/^(\d{2}\.\d{3}\.\d{3})\./', '$1/', $supplierCnpj),
            'FUNDO' => $manager, 'CNPJ_FUNDO' => $managerCnpj,
            'OBJETO DA ATA' => $this->capture('/A presente Ata tem por (?:o )?objeto\s+(.*?independentemente de transcri[çc][ãa]o\.)/ui', $flat),
            'pages' => range($start + 1, $end + 1)];
    }

    private function capture(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $match) ? trim($match[1]) : null;
    }

    private function date(string $text): ?string
    {
        if (! preg_match('/Niquel[âa]ndia\s*[–—-]?\s*(?:Goi[áa]s|GO),\s*(?:[\p{L}-]+(?:,| de)\s*)?(\d{1,2})\s+de\s+(\p{L}+)\s+de\s+(\d{4})/ui', $text, $match)) {
            return null;
        }
        $months = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];
        $month = $months[mb_strtolower($match[2])] ?? 0;

        return checkdate($month, (int) $match[1], (int) $match[3]) ? sprintf('%04d-%02d-%02d', $match[3], $month, $match[1]) : null;
    }

    private function ocrPage(string $path, int $page, string $cache): string
    {
        $target = $cache.'/'.$page.'.txt';
        if (is_file($target)) {
            return $this->reviewPage($cache, $page, file_get_contents($target));
        }
        $temporary = sys_get_temp_dir().'/ata-process-'.bin2hex(random_bytes(12));
        mkdir($temporary, 0700);
        try {
            (new Process(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-scale-to', '2300', '-singlefile', '-png', $path, $temporary.'/page']))->setTimeout(90)->mustRun();
            $ocr = new Process(['tesseract', $temporary.'/page.png', 'stdout', '-l', 'por', '--psm', '3'], env: ['OMP_THREAD_LIMIT' => '1']);
            $ocr->setTimeout(90)->mustRun();
            file_put_contents($target, $ocr->getOutput(), LOCK_EX);
            chmod($target, 0600);

            return $ocr->getOutput();
        } finally {
            foreach (glob($temporary.'/*') as $file) {
                unlink($file);
            }
            rmdir($temporary);
        }
    }

    private function reviewPage(string $cache, int $page, string $text): string
    {
        $path = $cache.'/'.$page.'.review.json';
        if (! is_file($path)) {
            return $text;
        }
        $review = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($review['original_text_sha256'], hash('sha256', $text))) {
            throw new RuntimeException('OCR alterado após conferência visual da página '.$page);
        }

        return $review['text'];
    }
}
