<?php

namespace App\Infrastructure\ContractStewards;

use App\Infrastructure\Persistence\TransactionGuard;
use Illuminate\Support\Facades\DB;

final class StewardLegacyTable
{
    public function sync(object $record): int
    {
        TransactionGuard::requireActive();
        $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        $values = array_intersect_key($raw, array_flip(['CPF_Gestor', 'RG_Gestor', 'Data_Nasc', 'Sexo', 'Email',
            'Fundo', 'Secretaria', 'Decreto', 'Data_Decreto', 'Celular', 'WhatsApp', 'Cod_Prodata']));
        $values += array_fill_keys(['CPF_Gestor', 'RG_Gestor', 'Data_Nasc', 'Sexo', 'Email', 'Fundo', 'Secretaria',
            'Decreto', 'Data_Decreto', 'Celular', 'WhatsApp', 'Cod_Prodata'], null);
        $values['Nome_Gestor'] = $record->name;
        $values['deleted_at'] = $record->deleted_at ?? null;
        $values['updated_at'] = $record->updated_at ?? now();
        $existing = DB::table('tb_Gestor_Cont')->where('contract_steward_id', $record->id)->lockForUpdate()->first();
        if ($existing) {
            DB::table('tb_Gestor_Cont')->where('ID_Gestor_Cont', $existing->ID_Gestor_Cont)->update($values);

            return $existing->ID_Gestor_Cont;
        }

        return DB::table('tb_Gestor_Cont')->insertGetId($values + ['contract_steward_id' => $record->id,
            'created_at' => $record->created_at ?? now()], 'ID_Gestor_Cont');
    }
}
