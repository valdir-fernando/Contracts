<?php

namespace App\Services\Reports;

use Generator;
use IteratorAggregate;
use RuntimeException;

/** Private, disk-backed rows shared by snapshot capture and file rendering. */
final class ReportRows implements IteratorAggregate
{
    private $stream;

    public function __construct()
    {
        $this->stream = tmpfile();
        if ($this->stream === false) {
            throw new RuntimeException('Não foi possível preparar os dados do relatório.');
        }
    }

    public function append(array $row): void
    {
        $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
        if (fwrite($this->stream, $line) !== strlen($line)) {
            throw new RuntimeException('Não foi possível armazenar os dados do relatório.');
        }
    }

    public function getIterator(): Generator
    {
        rewind($this->stream);
        while (($line = fgets($this->stream)) !== false) {
            yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! feof($this->stream)) {
            throw new RuntimeException('Não foi possível ler os dados do relatório.');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
