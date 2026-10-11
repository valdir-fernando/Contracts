<?php

namespace App\Http\Controllers;

use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('administration.catalogs', ['funds' => DB::table('contract_funds')->orderBy('name')->get(),
            'departments' => DB::table('contract_departments')->orderBy('name')->get(),
            'scopes' => AccessScope::orderBy('fund')->orderBy('department')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['fund', 'department', 'supplier', 'scope'])],
            'name' => 'required_unless:kind,scope|nullable|string|max:255', 'document' => 'nullable|string|max:32',
            'fund_id' => 'required_if:kind,scope|nullable|integer|exists:contract_funds,id',
            'department_id' => 'nullable|integer|exists:contract_departments,id']);
        if (in_array($data['kind'], ['fund', 'supplier']) && ! Normalizer::validDocument(Normalizer::document($data['document'] ?? ''))) {
            throw ValidationException::withMessages(['document' => 'Informe um CPF/CNPJ válido.']);
        }
        DB::transaction(function () use ($data, $request) {
            if ($data['kind'] === 'scope') {
                $fund = DB::table('contract_funds')->find($data['fund_id']);
                $department = empty($data['department_id']) ? null : DB::table('contract_departments')->find($data['department_id']);
                $record = AccessScope::firstOrCreate(['scope_key' => hash('sha256', 'native:'.$fund->id.':'.($department?->id ?? 'all'))],
                    ['fund_id' => $fund->id, 'department_id' => $department?->id, 'fund' => $fund->name, 'department' => $department?->name]);
                $id = $record->id;
            } else {
                $table = ['fund' => 'contract_funds', 'department' => 'contract_departments', 'supplier' => 'suppliers'][$data['kind']];
                $record = ['name' => $data['name'], 'name_key' => Normalizer::name($data['name']), 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now()];
                if ($data['kind'] !== 'department') {
                    $record += ['document' => $data['document'], 'document_key' => Normalizer::document($data['document'])];
                }
                $id = DB::table($table)->insertGetId($record);
            }
            ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'catalog_created', 'metadata' => ['kind' => $data['kind'], 'id' => $id], 'created_at' => now()]);
        });

        return redirect()->route('catalogs.index')->with('status', 'Cadastro salvo.');
    }
}
