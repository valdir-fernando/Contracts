<?php

namespace App\Infrastructure\Persistence;

use App\Application\Ports\TransactionManager;
use Illuminate\Support\Facades\DB;

final class LaravelTransactionManager implements TransactionManager
{
    public function run(callable $operation): mixed
    {
        return DB::transaction($operation, 3);
    }
}
