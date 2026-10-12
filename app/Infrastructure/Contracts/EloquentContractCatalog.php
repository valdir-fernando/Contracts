<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\Ports\ContractCatalog;
use App\Application\Exceptions\InputRejected;
use Illuminate\Support\Facades\DB;

final class EloquentContractCatalog implements ContractCatalog
{
    public function ownership(int $fundId, ?int $departmentId): array
    {
        $fund = DB::table('contract_funds')->find($fundId);
        $department = $departmentId ? DB::table('contract_departments')->find($departmentId) : null;
        if (! $fund) {
            throw new InputRejected(['fund_id' => 'Selecione um fundo cadastrado.']);
        }
        if ($departmentId && ! $department) {
            throw new InputRejected(['department_id' => 'Selecione uma secretaria cadastrada.']);
        }

        return ['fund_id' => $fund->id, 'department_id' => $department?->id, 'fundo_nome_snapshot' => $fund->name,
            'fundo_documento_snapshot' => $fund->document, 'secretaria_nome_snapshot' => $department?->name, 'ownership_resolved' => true];
    }

    public function supplierId(string $document): ?int
    {
        $suppliers = DB::table('suppliers')->where('document_key', $document)->limit(2)->pluck('id');

        return $suppliers->count() === 1 ? (int) $suppliers->first() : null;
    }
}
