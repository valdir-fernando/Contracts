<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SupplierCatalog
{
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
