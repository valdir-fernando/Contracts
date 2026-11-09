<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AtaNumbering
{
    /** The caller must save the new ata and its audit in this same transaction. */
    public function next(): string
    {
        TransactionGuard::requireActive();
        $year = today()->year;
        $this->validateYear($year);
        $counter = $this->lockCounter($year);
        $last = $this->integer((string) $counter->NUMERO);
        // Current reads include every fund, historical numbers and soft-deleted atas.
        foreach (DB::table('tb_ATA')->orderBy('ID')->lockForUpdate()->pluck('ATA') as $text) {
            $number = $this->parse($text);
            if ($number !== null && $number['ANO'] === $year) {
                $last = max($last, $number['NUMERO']);
            }
        }
        if ($last === PHP_INT_MAX) {
            throw ValidationException::withMessages(['ATA' => 'O limite anual de numeração das atas foi atingido.']);
        }
        $next = $last + 1;
        DB::table('tb_N_ATA')->where('ID', $counter->ID)->update(['NUMERO' => $next, 'updated_at' => now()]);

        return str_pad((string) $next, 3, '0', STR_PAD_LEFT).'/'.substr((string) $year, -2);
    }

    public function parse(string $text): ?array
    {
        if (! preg_match('/^\s*([0-9]+)\s*\/\s*([0-9]{2}|[0-9]{4})\s*$/D', $text, $parts)) {
            return null;
        }
        $year = strlen($parts[2]) === 2 ? 2000 + (int) $parts[2] : (int) $parts[2];
        $this->validateYear($year);

        return ['ANO' => $year, 'NUMERO' => $this->integer($parts[1])];
    }

    /** Reserves imported history without issuing a new number or lowering a counter. */
    public function reserveAtLeast(int $year, int $last): ?array
    {
        TransactionGuard::requireActive();
        $this->validateYear($year);
        if ($last < 0) {
            throw ValidationException::withMessages(['ATA' => 'Sequência de ata inválida.']);
        }
        $counter = $this->lockCounter($year);
        $previous = $this->integer((string) $counter->NUMERO);
        $value = max($last, $previous);
        if ($counter->was_created || $value !== $previous) {
            DB::table('tb_N_ATA')->where('ID', $counter->ID)->update(['NUMERO' => $value, 'updated_at' => now()]);

            return ['ANO' => $year, 'before' => $counter->was_created ? null : $previous, 'NUMERO' => $value];
        }

        return null;
    }

    /** Explicit system maintenance: preview by default, with transactional audit on apply. */
    public function synchronizeHistory(bool $apply = false): array
    {
        return DB::transaction(function () use ($apply) {
            $maxima = [];
            $unrecognized = 0;
            foreach (DB::table('tb_ATA')->orderBy('ID')->pluck('ATA') as $text) {
                $number = $this->parse($text);
                if ($number === null) {
                    $unrecognized++;

                    continue;
                }
                $maxima[$number['ANO']] = max($maxima[$number['ANO']] ?? 0, $number['NUMERO']);
            }
            ksort($maxima);
            $changes = [];
            foreach ($maxima as $year => $last) {
                if ($apply) {
                    $change = $this->reserveAtLeast($year, $last);
                } else {
                    $counter = DB::table('tb_N_ATA')->where('ANO', $year)->first();
                    $previous = $counter === null ? null : $this->integer((string) $counter->NUMERO);
                    $value = max($last, $previous ?? 0);
                    $change = $previous === null || $value !== $previous ? ['ANO' => $year, 'before' => $previous, 'NUMERO' => $value] : null;
                }
                if ($change !== null) {
                    $changes[] = $change;
                }
            }
            if ($apply && $changes !== []) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_numbering_synchronized',
                    'metadata' => ['source' => 'authorized_cli', 'changes' => $changes], 'created_at' => now()]);
            }

            return ['apply' => $apply, 'historical_maxima' => $maxima, 'changes' => $changes, 'unrecognized' => $unrecognized];
        }, 3);
    }

    private function lockCounter(int $year): object
    {
        // A unique year serializes even simultaneous first reservations from different funds.
        $created = DB::table('tb_N_ATA')->insertOrIgnore(['ANO' => $year, 'NUMERO' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $counter = DB::table('tb_N_ATA')->where('ANO', $year)->lockForUpdate()->firstOrFail();
        $counter->was_created = $created === 1;

        return $counter;
    }

    private function integer(string $digits): int
    {
        $digits = ltrim($digits, '0') ?: '0';
        $limit = (string) PHP_INT_MAX;
        if (! ctype_digit($digits) || strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            throw ValidationException::withMessages(['ATA' => 'Sequência de ata fora da faixa suportada.']);
        }

        return (int) $digits;
    }

    private function validateYear(int $year): void
    {
        if ($year < 1000 || $year > 9999) {
            throw ValidationException::withMessages(['ATA' => 'Exercício da ata inválido.']);
        }
    }
}
