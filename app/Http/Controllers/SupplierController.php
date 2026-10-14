<?php

namespace App\Http\Controllers;

use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use App\Services\SupplierCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function lookup(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        Gate::forUser($actor)->authorize('create', Contract::class);
        $data = $request->validate(['document' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
            if (! preg_match('/^[\d\s.\/\-]+$/D', $value) || ! Normalizer::validDocument(Normalizer::document($value))) {
                $fail('Informe um CPF ou CNPJ válido.');
            }
        }]]);
        $suppliers = SupplierCatalog::visibleTo($actor)->where('document_key', Normalizer::document($data['document']))->limit(2)->get();
        abort_unless($suppliers->count(), 404, 'Nenhum fornecedor disponível para este documento.');
        abort_if($suppliers->count() > 1, 409, 'Há mais de um cadastro para este documento. Use Selecionar fornecedor cadastrado.');

        return response()->json(['values' => SupplierCatalog::defaults($actor, $suppliers->first())],
            headers: ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, int $supplier)
    {
        Gate::authorize('create', Contract::class);
        $record = SupplierCatalog::visibleTo($request->user())->select(['id', 'name', 'document', 'document_key'])->where('id', $supplier)->first();
        abort_unless($record, 404);

        return view('suppliers.show', ['supplier' => $record,
            'contracts' => Contract::visibleTo($request->user())->where('supplier_id', $record->id)->latest('id')->paginate(20)]);
    }

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
