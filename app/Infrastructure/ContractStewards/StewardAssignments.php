<?php

namespace App\Infrastructure\ContractStewards;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StewardAssignments
{
    public const MUNICIPALITY = 'MUNICIPIO DE NIQUELANDIA';

    public function lockFunds(?int $recordId, ?int $destinationId): void
    {
        TransactionGuard::requireActive();
        $sourceKey = $recordId ? DB::table('contract_stewards')->where('id', $recordId)->value('fund_name_key') : null;
        DB::table('contract_funds')->where(function ($query) use ($sourceKey, $destinationId) {
            $query->where('id', $destinationId ?? -1);
            if ($sourceKey !== null) {
                $query->orWhere('name_key', $sourceKey);
            }
        })->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public function assertAvailable(object $fund, ?object $department, ?int $except = null): void
    {
        TransactionGuard::requireActive();
        $fundKey = Normalizer::name($fund->name);
        $others = DB::table('contract_stewards')->whereNull('deleted_at')->where('fund_name_key', $fundKey)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))->orderBy('id')
            ->lockForUpdate()->get(['department_name_key']);
        if ($fundKey !== self::MUNICIPALITY) {
            if ($others->isNotEmpty()) {
                throw ValidationException::withMessages(['fund_id' => 'Este Fundo já possui um Gestor de Contratos. Exclua ou altere o vínculo atual antes de cadastrar outro.']);
            }

            return;
        }
        if ($department === null && $others->isNotEmpty()) {
            throw ValidationException::withMessages(['department_id' => 'Todas as Secretarias exige que o Município não tenha outro Gestor de Contratos cadastrado.']);
        }
        if ($department !== null) {
            if ($others->contains(fn ($other) => empty($other->department_name_key))) {
                throw ValidationException::withMessages(['department_id' => 'Já existe um gestor para Todas as Secretarias. Exclua-o ou vincule-o a uma Secretaria antes de cadastrar outro.']);
            }
            if ($others->contains(fn ($other) => $other->department_name_key === Normalizer::name($department->name))) {
                throw ValidationException::withMessages(['department_id' => 'Esta Secretaria já possui um Gestor de Contratos. Exclua ou altere o vínculo atual antes de cadastrar outro.']);
            }
        }
    }
}
