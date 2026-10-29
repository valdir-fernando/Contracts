<?php

namespace App\Infrastructure\Dfd;

use App\Infrastructure\Persistence\TransactionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DfdNumbering
{
    public function reserveAtLeast(int $year, int $last): int
    {
        TransactionGuard::requireActive();
        DB::table('tb_N_DFD')->insertOrIgnore(['ANO' => $year, 'N_DFD' => 0]);
        $counter = DB::table('tb_N_DFD')->where('ANO', $year)->lockForUpdate()->first();
        $last = max($last, (int) $counter->N_DFD);
        DB::table('tb_N_DFD')->where('ID', $counter->ID)->update(['N_DFD' => $last]);

        return $last;
    }

    public function synchronizeHistory(): array
    {
        TransactionGuard::requireActive();
        $maxima = [];
        foreach (DB::table('tb_DFD')->orderBy('ID')->lockForUpdate()->pluck('N_DFD') as $number) {
            if (preg_match('/^\s*(\d+)\s*\/\s*(\d{2}|\d{4})\s*$/D', $number, $parts)) {
                $year = strlen($parts[2]) === 2 ? 2000 + (int) $parts[2] : (int) $parts[2];
                if ((int) $parts[1] > 2147483647) {
                    throw ValidationException::withMessages(['N_DFD' => 'Número histórico excede o limite anual da DFD.']);
                }
                $maxima[$year] = max($maxima[$year] ?? 0, (int) $parts[1]);
            }
        }
        ksort($maxima);
        foreach ($maxima as $year => $last) {
            $maxima[$year] = $this->reserveAtLeast($year, $last);
        }

        return $maxima;
    }

    public function next(int $year): string
    {
        TransactionGuard::requireActive();

        // The unique year serializes first allocation as well as subsequent saves,
        // including requests from different funds.
        DB::table('tb_N_DFD')->insertOrIgnore(['ANO' => $year, 'N_DFD' => 0]);
        $counter = DB::table('tb_N_DFD')->where('ANO', $year)->lockForUpdate()->first();
        $last = (int) $counter->N_DFD;
        $suffix = substr((string) $year, -2);

        // Current reads include historical and soft-deleted DFDs from every fund.
        $numbers = DB::table('tb_DFD')->orderBy('ID')->lockForUpdate()->pluck('N_DFD');
        foreach ($numbers as $number) {
            if (preg_match('/^\s*(\d+)\s*\/\s*(\d{2}|\d{4})\s*$/D', $number, $parts)
                && ($parts[2] === (string) $year || $parts[2] === $suffix)) {
                $last = max($last, (int) $parts[1]);
            }
        }
        // Compatible with the signed INT used by legacy tb_N_DFD installations.
        if ($last >= 2147483647) {
            throw ValidationException::withMessages(['N_DFD' => 'O limite anual de numeração de DFD foi atingido.']);
        }
        $next = $last + 1;
        DB::table('tb_N_DFD')->where('ID', $counter->ID)->update(['N_DFD' => $next]);

        return str_pad((string) $next, 3, '0', STR_PAD_LEFT).'/'.$suffix;
    }
}
