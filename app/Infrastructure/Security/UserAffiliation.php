<?php

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\AccessScope;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserAffiliation
{
    private const MUNICIPALITY = 'MUNICIPIO DE NIQUELANDIA';

    public function options(): array
    {
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('name')->get();
        $options = [];
        foreach ($funds as $fund) {
            if (Normalizer::name($fund->name) !== self::MUNICIPALITY) {
                $options['fund:'.$fund->id] = $fund->name;
            }
        }
        foreach (DB::table('contract_departments')->orderBy('name')->get() as $department) {
            $options['department:'.$department->id] = $department->name;
        }

        return $options;
    }

    public function assign(User $user, array $choices): void
    {
        TransactionGuard::requireActive();
        $ids = [];
        $snapshots = [];
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
        sort($choices);
        foreach ($choices as $choice) {
            [$kind, $id] = explode(':', $choice);
            if ($kind === 'legacy') {
                $scope = $user->scopes()->whereKey($id)->first();
                if (! $scope) {
                    throw ValidationException::withMessages(['affiliations' => 'Este vínculo existente não pertence ao usuário.']);
                }
                $ids[] = $scope->id;
                $snapshots[] = [$scope->fund, $scope->department];

                continue;
            }
            $department = null;
            if ($kind === 'department') {
                $municipalities = $funds
                    ->filter(fn ($fund) => Normalizer::name($fund->name) === self::MUNICIPALITY);
                if ($municipalities->count() !== 1) {
                    throw ValidationException::withMessages(['affiliations' => 'Cadastre um único Município de Niquelândia para vincular Secretarias.']);
                }
                $fund = $municipalities->first();
                $department = DB::table('contract_departments')->where('id', $id)->lockForUpdate()->first();
                if (! $department) {
                    throw ValidationException::withMessages(['affiliations' => 'Selecione uma Secretaria disponível.']);
                }
            } else {
                $fund = $funds->firstWhere('id', (int) $id);
                if (! $fund || Normalizer::name($fund->name) === self::MUNICIPALITY) {
                    throw ValidationException::withMessages(['affiliations' => 'Selecione um Fundo disponível ou uma Secretaria do Município.']);
                }
            }
            $scope = AccessScope::firstOrCreate(['scope_key' => hash('sha256', 'native:'.$fund->id.':'.($department?->id ?? 'all'))],
                ['fund_id' => $fund->id, 'department_id' => $department?->id, 'fund' => $fund->name, 'department' => $department?->name]);
            $ids[] = $scope->id;
            $snapshots[] = [$fund->name, $department?->name];
        }
        $user->scopes()->sync($ids);
        $user->fund = count($snapshots) === 1 ? $snapshots[0][0] : null;
        $user->department = count($snapshots) === 1 ? $snapshots[0][1] : null;
        $user->save();
    }
}
