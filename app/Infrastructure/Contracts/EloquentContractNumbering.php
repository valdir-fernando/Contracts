<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\Ports\ContractNumbering;
use App\Application\Exceptions\InputRejected;
use App\Domain\Contracts\ContractNumber;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use LogicException;
use OverflowException;

final class EloquentContractNumbering implements ContractNumbering
{
    public function nextNumber(AuthorizedContract $context, int $fundId): ContractNumber
    {
        TransactionGuard::requireActive();
        if ($context->contract->id !== null) {
            throw new LogicException('A numeração automática só pode ser atribuída na criação.');
        }
        $fund = DB::table('contract_funds')->where('id', $fundId)->lockForUpdate()->firstOrFail();
        $year = today()->year;
        $row = DB::table('tb_N_Contrato')->where('fund_id', $fundId)->where('ANO', $year)->lockForUpdate()->first();
        if (! $row) {
            $id = DB::table('tb_N_Contrato')->insertGetId(['fund_id' => $fundId, 'ANO' => $year, 'FUNDO' => $fund->name,
                'NUMERO' => 0, 'created_at' => now(), 'updated_at' => now()], 'ID');
            $row = DB::table('tb_N_Contrato')->where('ID', $id)->lockForUpdate()->firstOrFail();
        }
        try {
            $last = max(ContractNumber::integer((string) $row->NUMERO), self::highestUsed($fundId, $year));
            if ($last === PHP_INT_MAX) {
                throw new OverflowException;
            }
        } catch (OverflowException) {
            throw new InputRejected(['fund_id' => 'A sequência deste Fundo excedeu a faixa suportada. Solicite conferência administrativa.']);
        }
        $number = new ContractNumber($last + 1, $year);
        DB::table('tb_N_Contrato')->where('ID', $row->ID)->update(['NUMERO' => $number->sequence, 'FUNDO' => $fund->name, 'updated_at' => now()]);

        return $number;
    }

    /** Current locked reads include historical records and soft-deleted contracts. */
    public static function highestUsed(int $fundId, int $year): int
    {
        TransactionGuard::requireActive();
        $highest = 0;
        // Parse original text in PHP so legacy spaces/tabs are not missed by SQL LIKE.
        $contracts = Contract::withTrashed()->where('fund_id', $fundId)->orderBy('id')
            ->select(['id', 'numero', 'number_year', 'exercicio'])->lockForUpdate()->get();
        foreach ($contracts as $contract) {
            $knownYear = $contract->number_year ?? $contract->exercicio;
            if ($knownYear !== null && (int) $knownYear !== $year) {
                continue;
            }
            $highest = max($highest, ContractNumber::sequenceIn($contract->numero ?? '', $year) ?? 0);
        }

        return $highest;
    }
}
