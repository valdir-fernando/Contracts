<?php

namespace App\Infrastructure\Atas;

use RuntimeException;
use Symfony\Component\Process\Process;

class AtaAmendmentProductReader
{
    public function __construct(private AtaAmendmentProductParser $parser) {}

    public function read(string $directory, array $metadata): array
    {
        $root = realpath($directory);
        $path = $root === false ? false : realpath($root.'/'.$metadata['source_path']);
        if ($path !== false && (new AtaAmendmentSourcePolicy)->isUnsigned($path)) {
            throw new RuntimeException('Aditivo desconsiderado por ausência de assinatura, conforme orientação do usuário.');
        }
        if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || ! hash_equals($metadata['source_sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Origem do aditivo ausente ou alterada.');
        }
        $script = base_path('scripts/atas/extract_amendment_tables.py');
        $cache = storage_path('app/private/ata-amendment-products');
        if (! is_dir($cache)) {
            mkdir($cache, 0700, true);
        }
        $key = hash('sha256', hash_file('sha256', $script).'|'.$metadata['source_sha256'].'|'.$metadata['extraction']);
        $cacheFile = $cache.'/'.$key.'.json';
        if (is_file($cacheFile)) {
            $document = json_decode(file_get_contents($cacheFile), true, flags: JSON_THROW_ON_ERROR);
        } else {
            $arguments = ['/opt/ata-extract/bin/python', $script, $path];
            if ($metadata['extraction'] === 'ocr_por') {
                $arguments[] = '--ocr';
            }
            $process = new Process($arguments, base_path(), env: ['OMP_THREAD_LIMIT' => '1']);
            $process->setTimeout(900)->mustRun();
            $document = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            file_put_contents($cacheFile, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
            chmod($cacheFile, 0600);
        }
        if (! hash_equals($metadata['source_sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Aditivo alterado durante a leitura dos produtos.');
        }

        return $this->parseDocument($document, $metadata);
    }

    public function parseDocument(array $document, array $metadata): array
    {
        $reviewPath = storage_path('app/private/ata-product-reviews/'.$metadata['source_sha256'].'.json');
        if (is_file($reviewPath)) {
            $reviews = json_decode(file_get_contents($reviewPath), true, flags: JSON_THROW_ON_ERROR);
            foreach ($reviews as $review) {
                $page = $review['page'] - 1;
                $table = $review['table'];
                $original = $document['pages'][$page]['tables'][$table];
                $hash = hash('sha256', json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (! hash_equals($review['before_hash'], $hash)) {
                    throw new RuntimeException('A extração mudou após a conferência visual; revisar novamente.');
                }
                $document['pages'][$page]['tables'][$table] = $review['rows'];
                $document['reviews'][$review['page'].'/'.$table] = ['reason' => $review['reason'], 'before_hash' => $hash, 'original_table' => $original];
            }
        }

        return $this->parser->parse($document, $metadata);
    }
}
