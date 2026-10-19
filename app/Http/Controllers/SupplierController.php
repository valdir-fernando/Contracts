<?php

namespace App\Http\Controllers;

use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Suppliers\UpdateSupplier;
use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use App\Services\SupplierCatalog;
use App\Support\SupplierFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function registrationLookup(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        CatalogAccess::authorize($actor, 'supplier');
        $data = $request->validate(['document' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
            if (! preg_match('/^[\d\s.\/\-]+$/D', $value) || ! Normalizer::validDocument(Normalizer::document($value))) {
                $fail('Informe um CPF ou CNPJ válido.');
            }
        }]]);
        $suppliers = ($actor->canAccessCatalog('supplier') ? CatalogAccess::suppliers($actor) : SupplierCatalog::visibleTo($actor))->where('document_key', Normalizer::document($data['document']))
            ->select(['name', 'document_key', 'raw_payload'])->limit(2)->get();
        abort_if($suppliers->count() > 1, 409, 'Há mais de um fornecedor com este documento. Consulte o catálogo.');
        $values = null;
        if ($supplier = $suppliers->first()) {
            $raw = json_decode($supplier->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $values = ['name' => $supplier->name, 'document' => $supplier->document_key];
            foreach (SupplierFields::fields() as $field => $definition) {
                $values[$field] = is_string($raw[$field] ?? null) ? $raw[$field] : '';
            }
        }

        return response()->json(['found' => $values !== null, 'values' => $values], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function lookup(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        Gate::forUser($actor)->authorize('selectSupplier', Contract::class);
        $data = $request->validate(['document' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
            if (! preg_match('/^[\d\s.\/\-]+$/D', $value) || ! Normalizer::validDocument(Normalizer::document($value))) {
                $fail('Informe um CPF ou CNPJ válido.');
            }
        }]]);
        $suppliers = ($actor->canAccessCatalog('supplier') ? CatalogAccess::suppliers($actor) : SupplierCatalog::visibleTo($actor))->where('document_key', Normalizer::document($data['document']))->limit(2)->get();
        abort_unless($suppliers->count(), 404, 'Nenhum fornecedor disponível para este documento.');
        abort_if($suppliers->count() > 1, 409, 'Há mais de um cadastro para este documento. Use Selecionar fornecedor cadastrado.');

        return response()->json(['values' => SupplierCatalog::defaults($actor, $suppliers->first())],
            headers: ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, int $supplier)
    {
        Gate::authorize('selectSupplier', Contract::class);
        $actor = (new LockActor)->current($request->user());
        $query = ($actor->canAccessCatalog('supplier') ? CatalogAccess::suppliers($actor) : SupplierCatalog::visibleTo($actor))->select(['id', 'name', 'document', 'document_key']);
        if ($actor->canAccessCatalog('supplier')) {
            $query->addSelect(['raw_payload', 'updated_at']);
        }
        $record = $query->where('id', $supplier)->first();
        abort_unless($record, 404);
        $values = [];
        $revision = null;
        if ($actor->canAccessCatalog('supplier')) {
            $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $values = ['name' => $record->name, 'document' => $record->document_key ?: $record->document];
            foreach (SupplierFields::fields() as $field => $definition) {
                $values[$field] = is_string($raw[$field] ?? null) ? $raw[$field] : '';
            }
            $revision = UpdateSupplier::revision($record);
        }

        return view('suppliers.show', ['supplier' => $record,
            'supplierValues' => $values, 'supplierRevision' => $revision, 'canEditSupplier' => $actor->canAccessCatalog('supplier'),
            'contracts' => Contract::visibleTo($request->user())->where('supplier_id', $record->id)->latest('id')->paginate(20)]);
    }

    public function update(Request $request, int $supplier, UpdateSupplier $writer)
    {
        $writer->save($request->user(), $supplier, $request->all());

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Fornecedor atualizado.');
    }

    public function index(Request $request)
    {
        Gate::authorize('selectSupplier', Contract::class);
        $data = $request->validate(['q' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1']);
        $query = ($request->user()->canAccessCatalog('supplier') ? CatalogAccess::suppliers($request->user()) : SupplierCatalog::visibleTo($request->user()));
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
