<?php

namespace App\Infrastructure\Contracts;

use App\Models\Contract;
use App\Models\User;
use App\Services\ContractSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Eloquent read adapter; keeps catalog choices limited to the authorized scope. */
class ContractListingQuery
{
    public function for(User $actor, array $filters): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        $visible = Contract::visibleTo($actor);
        if (! empty($filters['deleted'])) {
            abort_unless($actor->isAdmin(), 403);
            $visible->onlyTrashed();
        }
        // Filter choices come only from accessible contracts, never from all catalogs.
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->whereIn('id', (clone $visible)->select('fund_id'))->orderBy('name')->get(['id', 'name']);
        $departments = DB::table('contract_departments')->whereIn('id', (clone $visible)->select('department_id'))->orderBy('name')->get(['id', 'name']);
        $years = (clone $visible)->whereNotNull('exercicio')->distinct()->orderByDesc('exercicio')->pluck('exercicio');
        $types = (clone $visible)->whereNotNull('tipo')->distinct()->orderBy('tipo')->pluck('tipo');
        $modalities = (clone $visible)->whereNotNull('modalidade')->distinct()->orderBy('modalidade')->pluck('modalidade');
        $contracts = clone $visible;
        ContractSearch::apply($contracts, $filters);
        ContractSearch::applyIdentityFilters($contracts, $filters);
        if ($filters['validity'] ?? null) {
            $contracts->withValidity($filters['validity']);
        }

        return [
            'contracts' => $contracts->select(['deleted_at', 'version', 'administrative_status', 'tipo', 'id', 'Contrato', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot',
                'fundo_nome_snapshot', 'secretaria_nome_snapshot', 'valor_total', 'vigencia_inicio', 'vigencia_fim_original',
                'vigencia_fim_atual', 'vigencia_em_revisao', 'ownership_resolved'])->orderBy(($filters['sort'] ?? '') === 'numero' ? 'Contrato' : ($filters['sort'] ?? 'exercicio'), $filters['direction'] ?? 'desc')->orderByDesc('id')->paginate(20)->withQueryString(),
            'funds' => $funds, 'departments' => $departments, 'years' => $years, 'types' => $types, 'modalities' => $modalities,
        ];
    }
}
