<?php

namespace App\Services\Legacy;

use Generator;
use RuntimeException;

class SqlDumpReader
{
    /** Reads data literals only. No SQL from the dump is executed. */
    public function rows(string $path, array $tables): Generator
    {
        $stream = fopen($path, 'rb');
        if (! $stream) {
            throw new RuntimeException('Não foi possível abrir o arquivo de referência.');
        }
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $comment = false;
        try {
            while (($line = fgets($stream)) !== false) {
                for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                    $char = $line[$i];
                    $next = $line[$i + 1] ?? '';
                    if ($comment) {
                        if ($char === '*' && $next === '/') {
                            $comment = false;
                            $i++;
                        }

                        continue;
                    }
                    if (! $quoted && ($char === '#' || ($char === '-' && $next === '-' && ctype_space($line[$i + 2] ?? ' ')))) {
                        break;
                    }
                    if (! $quoted && $char === '/' && $next === '*') {
                        $comment = true;
                        $i++;

                        continue;
                    }
                    $buffer .= $char;
                    if ($escaped) {
                        $escaped = false;

                        continue;
                    }
                    if ($quoted && $char === '\\') {
                        $escaped = true;

                        continue;
                    }
                    if ($char === "'") {
                        if ($quoted && $next === "'") {
                            $buffer .= $next;
                            $i++;
                        } else {
                            $quoted = ! $quoted;
                        }
                    }
                    if (! $quoted && $char === ';') {
                        if (preg_match('/^\s*INSERT\s+INTO\s+`([^`]+)`\s*\((.*?)\)\s*VALUES\s*(.*);\s*$/s', $buffer, $match) && in_array($match[1], $tables, true)) {
                            preg_match_all('/`([^`]+)`/', $match[2], $columns);
                            foreach ($this->values($match[3]) as $values) {
                                if (count($values) !== count($columns[1])) {
                                    throw new RuntimeException('Quantidade de campos inválida em '.$match[1].'.');
                                }
                                yield ['table' => $match[1], 'data' => array_combine($columns[1], $values)];
                            }
                        }
                        $buffer = '';
                    }
                    if (strlen($buffer) > 64 * 1024 * 1024) {
                        throw new RuntimeException('Instrução SQL excede o tamanho permitido.');
                    }
                }
                if (! $quoted && ! $comment) {
                    $buffer .= "\n";
                }
            }
            if ($quoted || $comment || trim($buffer) !== '') {
                throw new RuntimeException('Arquivo SQL incompleto.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function values(string $sql): Generator
    {
        $i = 0;
        $length = strlen($sql);
        while ($i < $length) {
            while ($i < $length && (ctype_space($sql[$i]) || $sql[$i] === ',')) {
                $i++;
            }
            if ($i === $length) {
                break;
            }
            if ($sql[$i++] !== '(') {
                throw new RuntimeException('Sintaxe de registro SQL não suportada.');
            }
            $row = [];
            while (true) {
                while ($i < $length && ctype_space($sql[$i])) {
                    $i++;
                }
                if ($i >= $length) {
                    throw new RuntimeException('Registro SQL incompleto.');
                }
                if ($sql[$i] === "'") {
                    $i++;
                    $value = '';
                    $closed = false;
                    while ($i < $length) {
                        $char = $sql[$i++];
                        if ($char === '\\') {
                            $escape = $sql[$i++] ?? '';
                            $value .= match ($escape) {
                                '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a",
                                '\\', "'", '"' => $escape, '%', '_' => '\\'.$escape,
                                default => throw new RuntimeException('Escape SQL não suportado.'),
                            };
                        } elseif ($char === "'") {
                            if (($sql[$i] ?? '') === "'") {
                                $value .= "'";
                                $i++;
                            } else {
                                $closed = true;
                                break;
                            }
                        } else {
                            $value .= $char;
                        }
                    }
                    if (! $closed) {
                        throw new RuntimeException('Texto SQL incompleto.');
                    }
                } else {
                    $start = $i;
                    while ($i < $length && ! in_array($sql[$i], [',', ')'], true)) {
                        $i++;
                    }
                    $value = trim(substr($sql, $start, $i - $start));
                    if (strcasecmp($value, 'NULL') === 0) {
                        $value = null;
                    } elseif (! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                        throw new RuntimeException('Somente valores literais são permitidos.');
                    }
                }
                $row[] = $value;
                while ($i < $length && ctype_space($sql[$i])) {
                    $i++;
                }
                $separator = $sql[$i++] ?? '';
                if ($separator === ')') {
                    break;
                }
                if ($separator !== ',') {
                    throw new RuntimeException('Separador SQL inválido.');
                }
            }
            yield $row;
        }
    }
}
