<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use LogicException;

final class TransactionGuard
{
    public static function requireActive(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A autorização, a escrita e a auditoria exigem uma transação ativa.');
        }
    }
}
