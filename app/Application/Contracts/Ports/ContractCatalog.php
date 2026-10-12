<?php

namespace App\Application\Contracts\Ports;

interface ContractCatalog
{
    /** Resolve trusted ownership snapshots from the catalogs, not caller fields. */
    public function ownership(int $fundId, ?int $departmentId): array;

    public function supplierId(string $document): ?int;
}
