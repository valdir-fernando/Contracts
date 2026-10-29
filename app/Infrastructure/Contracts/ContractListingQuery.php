<?php

namespace App\Infrastructure\Contracts;

use App\Models\Contract;
use App\Models\User;
use App\Services\ContractSearch;
use Illuminate\Support\Facades\Gate;

/** Administrative read adapter for restoring soft-deleted contracts. */
class ContractListingQuery
{
    public function for(User $actor, array $filters): array
    {
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        abort_unless($actor->isAdmin(), 403);
        $contracts = Contract::visibleTo($actor)->onlyTrashed();
        ContractSearch::applyIdentityFilters($contracts, ['q' => $filters['q'] ?? '']);

        return ['contracts' => $contracts->select(['id', 'Contrato', 'processo', 'fornecedor_nome_snapshot',
            'fundo_nome_snapshot', 'secretaria_nome_snapshot', 'version', 'deleted_at'])
            ->orderByDesc('deleted_at')->orderByDesc('id')->paginate(20)->withQueryString()];
    }
}
