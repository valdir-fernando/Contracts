<?php

namespace App\Infrastructure\Security;

use App\Models\Contract;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CatalogAccess
{
    public static function authorize(User $actor, string $kind): void
    {
        abort_unless($actor->canAccessCatalog($kind), 403);
    }

    public static function funds(User $actor): Builder
    {
        $query = DB::table('contract_funds')->whereNull('deleted_at');

        return $actor->isAdmin() ? $query : $query->whereIn('id', self::scopes($actor)->pluck('fund_id'));
    }

    public static function departments(User $actor): Builder
    {
        $query = DB::table('contract_departments');
        if ($actor->isAdmin()) {
            return $query;
        }
        $scopes = self::scopes($actor);
        $municipalIds = self::funds($actor)->get()->filter(fn ($fund) => Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA')->pluck('id');
        $municipalScopes = $scopes->whereIn('fund_id', $municipalIds);
        if ($municipalScopes->contains(fn ($scope) => ! $scope->department_id && ! $scope->department)) {
            return $query;
        }

        return $query->whereIn('id', $municipalScopes->pluck('department_id')->filter());
    }

    private static function scopes(User $actor)
    {
        return $actor->scopes()->whereNotNull('fund_id')->get()->filter(fn ($scope) => ! $scope->department || $scope->department_id);
    }

    public static function byOrganization(User $actor, Builder $query, string $fundColumn, ?string $departmentColumn = null, bool $normalized = true): Builder
    {
        if ($actor->isAdmin()) {
            return $query;
        }
        $funds = self::funds($actor)->get()->keyBy('id');
        $departments = DB::table('contract_departments')->get()->keyBy('id');

        return $query->where(function ($query) use ($actor, $funds, $departments, $fundColumn, $departmentColumn, $normalized) {
            $query->whereRaw('1 = 0');
            foreach (self::scopes($actor) as $scope) {
                $fund = $funds->get($scope->fund_id);
                $department = $departments->get($scope->department_id);
                if (! $fund || ($scope->department_id && (! $departmentColumn || ! $department))) {
                    continue;
                }
                $query->orWhere(function ($query) use ($fund, $department, $fundColumn, $departmentColumn, $normalized) {
                    $query->whereIn($fundColumn, $normalized ? [Normalizer::name($fund->name)] : [$fund->name, Normalizer::name($fund->name)]);
                    if ($department) {
                        $query->whereIn($departmentColumn, $normalized ? [Normalizer::name($department->name)] : [$department->name, Normalizer::name($department->name)]);
                    }
                });
            }
        });
    }

    public static function destination(User $actor, int $fundId, ?int $departmentId): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        abort_unless(self::funds($actor)->where('id', $fundId)->exists() && self::scopes($actor)->contains(fn ($scope) => (int) $scope->fund_id === $fundId
            && (! $scope->department_id || (int) $scope->department_id === $departmentId)), 403);
    }

    public static function suppliers(User $actor): Builder
    {
        $query = DB::table('suppliers');
        if ($actor->isAdmin()) {
            return $query;
        }

        return $query->where(function ($query) use ($actor) {
            $query->whereIn('id', Contract::visibleTo($actor)->select('supplier_id'))
                ->orWhereIn('id', DB::table('activity_events')->where('actor_id', $actor->id)->where('action', 'catalog_created')
                    ->where('metadata->kind', 'supplier')->select('metadata->id'));
        });
    }
}
