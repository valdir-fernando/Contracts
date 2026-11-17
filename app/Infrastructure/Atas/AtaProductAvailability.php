<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AtaProductAvailability
{
    /** Callers performing writes must lock the matching catalog row first. */
    public function remaining(string $process, string $item, ?string $limit, bool $lock = false): string
    {
        $limit = DecimalAmount::normalize($limit);
        if ($limit === null || bccomp($limit, '0', 4) < 0) {
            throw ValidationException::withMessages(['item' => 'O produto tem saldo inválido no cadastro.']);
        }
        $query = DB::table('tb_PRODUTOS_ATA')->whereRaw('TRIM(PROCESSO) = ?', [trim($process)])
            ->whereRaw('TRIM(ITEM) = ?', [trim($item)])->whereNull('deleted_at')->orderBy('ID');
        if ($lock) {
            TransactionGuard::requireActive();
            $query->lockForUpdate();
        }
        $used = '0.0000';
        foreach ($query->get(['QTD']) as $row) {
            $quantity = DecimalAmount::normalize($row->QTD);
            if ($quantity === null || bccomp($quantity, '0', 4) < 0) {
                throw ValidationException::withMessages(['item' => 'Há quantidades inválidas nos lançamentos deste Item e Processo. Revise o cadastro.']);
            }
            $used = bcadd($used, $quantity, 4);
        }

        return bccomp($used, $limit, 4) >= 0 ? '0.0000' : bcsub($limit, $used, 4);
    }
}
