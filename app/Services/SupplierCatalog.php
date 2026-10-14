<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SupplierCatalog
{
    public static function defaults(User $actor, object $supplier): array
    {
        $raw = json_decode($supplier->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        $values = ['fornecedor_nome_snapshot' => $supplier->name,
            'fornecedor_documento_snapshot' => $supplier->document_key ?: $supplier->document];
        $fields = ['doc_prof' => 'Doc_Prof', 'end_fornec' => 'Endereco', 'socio' => 'Nome_Socio'];
        if ($actor->isAdmin()) {
            $fields += ['rg_fornec' => 'RG', 'pis_pasep' => 'PIS_PASEP', 'cpf_socio' => 'CPF_Socio', 'rg_socio' => 'RG_Socio'];
        }
        foreach ($fields as $field => $source) {
            $value = $raw[$source] ?? null;
            $values[$field] = is_string($value) ? trim($value) : null;
        }
        if (isset($values['cpf_socio'])) {
            $cpf = Normalizer::document($values['cpf_socio']);
            $values['cpf_socio'] = strlen($cpf) === 11 && Normalizer::validDocument($cpf) ? $cpf : null;
        }

        return $values;
    }

    public static function visibleTo(User $user): Builder
    {
        $query = DB::table('suppliers');
        if (! $user->isAdmin()) {
            $query->whereIn('id', Contract::visibleTo($user)->select('supplier_id'))
                ->whereRaw('LENGTH(document_key) = 14');
        }

        return $query;
    }
}
