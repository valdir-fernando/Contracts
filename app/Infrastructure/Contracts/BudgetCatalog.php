<?php

namespace App\Infrastructure\Contracts;

use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;

final class BudgetCatalog
{
    private function departmentKey(?string $name): string
    {
        return preg_replace('/^SEC\.? MUNICIPAL\b/', 'SECRETARIA MUNICIPAL', Normalizer::name($name));
    }

    /** Comparison informs the confirmation only; it never filters the catalog result. */
    public function matchesScope(array $result, ?int $fundId, ?int $departmentId): bool
    {
        $fund = $fundId ? DB::table('contract_funds')->find($fundId) : null;
        $department = $departmentId ? DB::table('contract_departments')->find($departmentId) : null;

        return $fund && (! $departmentId || $department)
            && Normalizer::name($result['fund'] ?? '') === Normalizer::name($fund->name)
            && $this->departmentKey($result['department'] ?? '') === $this->departmentKey($department?->name);
    }

    /** Catalog lookup by Ficha only; the HTTP boundary authorizes access to the contract. */
    public function lookup(string $ficha): array
    {
        $row = DB::table('tb_Dotacao')->whereRaw('TRIM(Ficha) = ?', [$ficha])
            ->orderBy('ID_Dotacao')->first(['Descricao', 'Dotacao', 'Fundo', 'Secretaria']);

        return $row
            ? ['status' => 'found', 'description' => $row->Descricao ?? '', 'allocation' => $row->Dotacao ?? '',
                'fund' => $row->Fundo ?? '', 'department' => $row->Secretaria ?? '']
            : ['status' => 'not_found', 'description' => '', 'allocation' => ''];
    }
}
