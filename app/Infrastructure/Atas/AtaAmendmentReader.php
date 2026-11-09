<?php

namespace App\Infrastructure\Atas;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AtaAmendmentReader
{
    public function read(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Pasta de aditivos não encontrada.');
        }
        $paths = [];
        $policy = new AtaAmendmentSourcePolicy;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (strtolower($file->getExtension()) === 'pdf' && ! $policy->isUnsigned($file->getPathname())) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        $entries = [];
        $cache = storage_path('app/private/ata-amendment-cache');
        if (! is_dir($cache)) {
            mkdir($cache, 0700, true);
        }
        foreach ($paths as $path) {
            $hash = hash_file('sha256', $path);
            $cacheFile = $cache.'/'.hash('sha256', 'v1|'.$hash.'|'.basename($path)).'.json';
            $cached = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : null;
            if (is_array($cached) && isset($cached['entry'], $cached['extraction']) && $cached['entry']['issues'] === []) {
                ['entry' => $entry, 'extraction' => $extraction] = $cached;
            } else {
                $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
                $process->setTimeout(60)->mustRun();
                $entry = $this->parse($process->getOutput(), basename($path));
                $extraction = 'pdf_text';
                if ($entry['issues'] !== []) {
                    $entry = $this->parse($this->ocr($path), basename($path));
                    $extraction = 'ocr_por';
                }
                file_put_contents($cacheFile, json_encode(['entry' => $entry, 'extraction' => $extraction], JSON_THROW_ON_ERROR), LOCK_EX);
                chmod($cacheFile, 0600);
            }
            if (! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Aditivo alterado durante a leitura.');
            }
            $entries[] = $entry + ['source_path' => str_replace('\\', '/', substr($path, strlen($root) + 1)),
                'source_sha256' => $hash, 'source_size' => filesize($path), 'extraction' => $extraction];
        }

        return $entries;
    }

    public function parse(string $text, string $filename): array
    {
        $text = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $text = str_replace("\u{00AD}", '', $text);
        $flat = trim(preg_replace('/\s+/u', ' ', $text));
        $flat = preg_replace('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-)\s+(\d{2})/', '$1$2', $flat);
        preg_match('/_ATA_(\d+)[_-](\d{4}|\d{2})(?:\D|$)/i', $filename, $fileNumber);
        $expected = isset($fileNumber[1]) ? $this->key($fileNumber[1].'/'.$fileNumber[2]) : null;
        preg_match_all('/ATA(?:\s+DE\s+REGISTRO\s+DE\s+PRE[ÇC]OS)?\s*(?:N[º°o.]*\s*)?\s*(\d+\s*\/\s*(?:\d{4}|\d{2}))/ui', $flat, $matches);
        $numbers = array_values(array_unique(array_map(fn ($value) => $this->key($value), $matches[1])));
        $issues = [];
        if (count($numbers) !== 1 || $expected !== null && ($numbers[0] ?? null) !== $expected) {
            $issues[] = 'identificacao_da_ata_ambigua_ou_divergente';
        }
        $number = $numbers[0] ?? $expected;
        $ordinal = null;
        if (preg_match('/(?:\b(\d{1,3})\s*[º°o]?\s*TERMO\s+ADITIVO|TERMO\s+ADITIVO\s+(\d{1,3}))/ui', $flat, $order)) {
            $ordinal = (int) ($order[1] !== '' ? $order[1] : $order[2]);
        } elseif (preg_match('/PRIMEIRO\s+TERMO\s+ADITIVO/ui', $flat)) {
            $ordinal = 1;
        }
        if (! $ordinal) {
            $issues[] = 'numero_do_aditivo_nao_identificado';
        }
        $original = $this->capture('/PROCESSO(?:\s+ADMINISTRATIVO)?\s+(?:BASE|DE\s+ORIGEM|ORIGIN[ÁA]RIO|DO\s+CONTRATO|DA\s+ATA)\s*(?:N[º°o.]*)?\s*:?\s*(\d{8,})/ui', $flat);
        $amendmentProcess = $this->capture('/PROCESSO(?:\s+ADMINISTRATIVO)?\s+(?:DE\s+PRORROGA[ÇC][ÃA]O|DO\s+ADITIVO)\s*(?:N[º°o.]*)?\s*:?\s*(\d{8,})/ui', $flat);
        $procurement = $this->capture('/PREG[ÃA]O\s+ELETR[ÔO]NICO\s*(?:N[º°o.]*)?\s*:?\s*(\d+\s*\/\s*(?:\d{4}|\d{2}))/ui', $flat);
        preg_match_all('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', $flat, $documents);
        $documents = array_values(array_unique($documents[0]));
        $clause = $this->capture('/CL[ÁA]USULA\s+\p{L}+\s*[–—-]?\s*DA\s+VIG[ÊE]NCIA(.*?)(?=CL[ÁA]USULA|$)/ui', $flat);
        $datePattern = '(?:\d{1,2}[\/.]\d{1,2}[\/.]\d{4}|\d{1,2}\s+de\s+\p{L}+\s+de\s+\d{4})';
        preg_match_all('/('.$datePattern.')\s+(?:a|até)\s+('.$datePattern.')/ui', $clause ?? '', $ranges, PREG_SET_ORDER);
        $valid = [];
        foreach ($ranges as $range) {
            $start = $this->date($range[1]);
            $end = $this->date($range[2]);
            if ($start !== null && $end !== null && $end >= $start) {
                $valid[$start.'|'.$end] = [$start, $end];
            }
        }
        uasort($valid, fn ($a, $b) => $b[1] <=> $a[1]);
        $chosen = array_values($valid)[0] ?? [null, null];
        if ($chosen[1] === null && preg_match('/termo\s+final(?:\s+de\s+vig[êe]ncia)?\s+(?:em|para)\s+('.$datePattern.')/ui', $clause ?? '', $finalOnly)) {
            $chosen[1] = $this->date($finalOnly[1]);
        }
        if ($chosen[1] === null) {
            $issues[] = 'vigencia_do_aditivo_nao_identificada';
        }
        if ($original === null || $procurement === null) {
            $issues[] = 'processo_ou_pregao_nao_identificado';
        }

        return ['ATA' => $issues !== [] && $expected !== null ? $expected : $number, 'NUMERO' => $ordinal,
            'PROCESSO_ORIGEM' => $original, 'PROCESSO_ADITIVO' => $amendmentProcess,
            'N_LICIT' => $procurement === null ? null : $this->key($procurement), 'CNPJ_FORNECEDOR' => $documents[1] ?? null,
            'INICIO_ADITIVO' => $chosen[0], 'FINAL_ADITIVO' => $chosen[1], 'issues' => $issues];
    }

    public function key(string $number): ?string
    {
        if (! preg_match('/^\s*(\d+)\s*\/\s*(\d{4}|\d{2})\s*$/D', $number, $parts)) {
            return null;
        }
        $year = strlen($parts[2]) === 2 ? 2000 + (int) $parts[2] : (int) $parts[2];

        return str_pad(ltrim($parts[1], '0') ?: '0', 3, '0', STR_PAD_LEFT).'/'.$year;
    }

    private function date(string $text): ?string
    {
        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/D', $text, $parts)) {
            [$day, $month, $year] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } elseif (preg_match('/^(\d{1,2})\s+de\s+(\p{L}+)\s+de\s+(\d{4})$/uiD', $text, $parts)) {
            $months = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];
            [$day, $month, $year] = [(int) $parts[1], $months[mb_strtolower($parts[2])] ?? 0, (int) $parts[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function capture(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $parts) ? trim($parts[1]) : null;
    }

    private function ocr(string $path): string
    {
        $directory = sys_get_temp_dir().'/ata-ocr-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        try {
            $render = new Process(['pdftoppm', '-scale-to', '2200', '-png', $path, $directory.'/page']);
            $render->setTimeout(180)->mustRun();
            $text = '';
            $pages = glob($directory.'/page-*.png');
            natsort($pages);
            foreach ($pages as $page) {
                $ocr = new Process(['tesseract', $page, 'stdout', '-l', 'por', '--psm', '3']);
                $ocr->setTimeout(120)->mustRun();
                $text .= "\n".$ocr->getOutput();
            }

            return $text;
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
