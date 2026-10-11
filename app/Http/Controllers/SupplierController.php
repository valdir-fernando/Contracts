<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use App\Services\SupplierCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', Contract::class);
        $data = $request->validate(['q' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1']);
        $query = SupplierCatalog::visibleTo($request->user());
        if ($search = trim($data['q'] ?? '')) {
            $query->where(function ($query) use ($search) {
                $query->whereLike('name', '%'.$search.'%');
                $digits = Normalizer::document($search);
                if (preg_match('/^[\d\s.\/\-]+$/', $search) && strlen($digits) >= 4) {
                    $query->orWhereLike('document_key', '%'.$digits.'%');
                }
            });
        }

        return view('suppliers.index', ['suppliers' => $query->select(['id', 'name', 'document', 'document_key'])->orderBy('name')->orderBy('id')->paginate(20)->withQueryString()]);
    }
}
