<?php

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;

final class InspectorCatalog
{
    public function __construct(private SelectedFund $funds) {}

    public function defaults(User $actor, int $fundId, ?int $departmentId, bool $forSave = false): array
    {
        if ($forSave) {
            TransactionGuard::requireActive();
        }
        $fund = $this->funds->current($actor, $fundId);
        abort_unless($fund, 403);
        $fundKey = Normalizer::name($fund->name);
        $query = DB::table('contract_inspectors')->whereNull('deleted_at')->where('fund_name_key', $fundKey)
            ->where(function ($query) {
                $query->whereNull('raw_payload->Situacao')->orWhere('raw_payload->Situacao', 'Ativo');
            });
        $values = ['nome_fiscal' => null, 'decreto_fiscal' => null];
        if ($actor->isAdmin() || $forSave) {
            $values += ['cpf_fiscal' => null, 'rg_fiscal' => null];
        }
        if (DB::table('contract_funds')->whereNull('deleted_at')->where('name_key', $fundKey)->count() !== 1) {
            return $values;
        }
        if ($fundKey === 'MUNICIPIO DE NIQUELANDIA') {
            $allowed = $actor->isAdmin() || $actor->scopes()->where('fund_id', $fundId)->where(function ($query) use ($departmentId) {
                $query->whereNull('department')->orWhere('department', '');
                if ($departmentId) {
                    $query->orWhere('department_id', $departmentId);
                }
            })->exists();
            if ($forSave || $departmentId) {
                abort_unless($allowed, 403);
            }
            if (! $departmentId) {
                return $values;
            }
            $department = DB::table('contract_departments')->find($departmentId);
            abort_unless($department, 404);
            $key = Normalizer::name($department->name);
            if (DB::table('contract_departments')->where('name_key', $key)->count() !== 1) {
                return $values;
            }
            $query->where('department_name_key', $key);
        } else {
            abort_if($departmentId !== null, 422, 'Este Fundo não possui secretarias.');
        }
        if ($forSave) {
            $query->lockForUpdate();
        }
        $matches = $query->orderBy('id')->limit(2)->get();
        if ($matches->count() !== 1) {
            return $values;
        }
        $raw = json_decode($matches->first()->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        $values['nome_fiscal'] = $matches->first()->name;
        $values['decreto_fiscal'] = $raw['Decreto'] ?? null;
        if ($actor->isAdmin() || $forSave) {
            $cpf = Normalizer::document($raw['CPF_Fiscal'] ?? '');
            $values['cpf_fiscal'] = strlen($cpf) === 11 && Normalizer::validDocument($cpf) ? $cpf : null;
            $values['rg_fiscal'] = $raw['RG_Fiscal'] ?? null;
        }

        return $values;
    }
}
