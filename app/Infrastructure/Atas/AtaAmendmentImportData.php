<?php

namespace App\Infrastructure\Atas;

/** Translation at the documentary import boundary; business columns have one name. */
final class AtaAmendmentImportData
{
    private const COLUMNS = [
        'NUMERO' => 'ADT', 'PROCESSO_ORIGEM' => 'Proc_ATA', 'PROCESSO_ADITIVO' => 'Proc_ADT',
        'INICIO_ADITIVO' => 'INICIO', 'FINAL_ADITIVO' => 'FINAL',
    ];

    public static function canonical(array $document): array
    {
        foreach (self::COLUMNS as $source => $target) {
            if (array_key_exists($source, $document)) {
                $document[$target] = $document[$source];
                unset($document[$source]);
            }
        }

        return $document;
    }

    public static function documentary(array $record): array
    {
        foreach (self::COLUMNS as $target => $source) {
            $record[$target] = $record[$source] ?? null;
        }

        return $record;
    }
}
