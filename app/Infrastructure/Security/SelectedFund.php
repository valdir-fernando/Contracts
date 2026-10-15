<?php

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SelectedFund
{
    public function options(User $actor): Collection
    {
        $query = DB::table('contract_funds');
        if (! $actor->is_active || $actor->trashed()) {
            return collect();
        }
        if (! $actor->isAdmin()) {
            $query->whereIn('id', $actor->scopes()->whereNotNull('fund_id')->where(function ($query) {
                $query->whereNull('department')->orWhere('department', '')->orWhereNotNull('department_id');
            })->pluck('fund_id'));
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    public function allows(User $actor, int $fundId): bool
    {
        return $actor->is_active && ! $actor->trashed() && ($actor->isAdmin()
            || $actor->scopes()->where('fund_id', $fundId)->where(function ($query) {
                $query->whereNull('department')->orWhere('department', '')->orWhereNotNull('department_id');
            })->exists());
    }

    public function current(User $actor, ?int $fundId): ?object
    {
        if (! $fundId || ! $this->allows($actor, $fundId)) {
            return null;
        }

        return DB::table('contract_funds')->where('id', $fundId)->first();
    }

    public function defaults(User $actor, int $fundId, bool $forPersistence = false): array
    {
        if ($forPersistence) {
            TransactionGuard::requireActive();
        }
        $fund = $this->current($actor, $fundId);
        if (! $fund) {
            return [];
        }
        $legacy = json_decode($fund->raw_payload, true) ?? [];
        $fundKey = Normalizer::name($fund->name);
        $managers = DB::table('contract_managers')->where('fund_name_key', $fundKey)->limit(2)->get();
        // Names in the legacy source are not foreign keys: ambiguous links stay empty.
        $uniqueFund = DB::table('contract_funds')->where('name_key', $fundKey)->count() === 1;
        $manager = $uniqueFund && $managers->count() === 1 ? json_decode($managers->first()->raw_payload, true) : [];
        $values = ['fund_id' => $fund->id, 'fundo_nome_snapshot' => $fund->name,
            'fundo_documento_snapshot' => $fund->document, 'end_fundo' => $legacy['End_Fundo'] ?? null,
            'nome_gestor' => $manager['Nome_Gestor'] ?? null, 'cargo_gestor' => $manager['Cargo'] ?? null];
        if ($actor->isAdmin() || $forPersistence) {
            $cpf = Normalizer::document($manager['CPF_Gestor'] ?? '');
            $values += ['cpf_gestor' => strlen($cpf) === 11 && Normalizer::validDocument($cpf) ? $cpf : null,
                'rg_gestor' => $manager['RG_Gestor'] ?? null];
        }

        return $values;
    }
}
