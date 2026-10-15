<?php

namespace App\Http\Controllers;

use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use App\Support\SupplierFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(private LockActor $actors) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('administration.catalogs', ['funds' => DB::table('contract_funds')->orderBy('name')->get(),
            'departments' => DB::table('contract_departments')->orderBy('name')->get(),
            'scopes' => AccessScope::orderBy('fund')->orderBy('department')->get()]);
    }

    public function show(Request $request, string $kind, int $catalog)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(in_array($kind, ['fund', 'department'], true), 404);
        $table = $kind === 'fund' ? 'contract_funds' : 'contract_departments';
        $columns = $kind === 'fund' ? ['id', 'name', 'document'] : ['id', 'name'];
        $record = DB::table($table)->select($columns)->where('id', $catalog)->first();
        abort_unless($record, 404);

        return view('administration.catalog-detail', ['record' => $record, 'kind' => $kind,
            'contracts' => Contract::visibleTo($request->user())
                ->where($kind === 'fund' ? 'fund_id' : 'department_id', $record->id)->latest('id')->paginate(20)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $kind = $request->input('kind');
        $bag = in_array($kind, ['fund', 'department', 'supplier']) ? 'catalog-'.$kind : 'default';
        $data = $request->validateWithBag($bag, ['kind' => ['required', Rule::in(['fund', 'department', 'supplier', 'scope'])],
            'return_to' => ['nullable', Rule::in(['catalogs.index', 'suppliers.index'])],
            'name' => 'required_unless:kind,scope|nullable|string|max:255', 'document' => 'nullable|string|max:32',
            'fund_id' => 'required_if:kind,scope|nullable|integer|exists:contract_funds,id',
            'department_id' => 'nullable|integer|exists:contract_departments,id']);
        if (in_array($data['kind'], ['fund', 'supplier']) && ! Normalizer::validDocument(Normalizer::document($data['document'] ?? ''))) {
            throw ValidationException::withMessages(['document' => 'Informe um CPF/CNPJ válido.'])->errorBag($bag);
        }
        DB::transaction(function () use ($data, $request, $bag) {
            $actor = $this->actors->lock($request->user());
            abort_unless($actor->isAdmin(), 403);
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
                if ($data['kind'] === 'supplier') {
                    if (DB::table('suppliers')->where('document_key', $record['document_key'])->lockForUpdate()->first(['id'])) {
                        throw ValidationException::withMessages(['document' => 'Fornecedor já cadastrado para este CPF/CNPJ. Consulte o catálogo.'])->errorBag($bag);
                    }
                    $rules = [];
                    foreach (SupplierFields::fields() as $field => [$label, $type]) {
                        $rules[$field] = ['nullable', 'string', 'max:'.($type === 'textarea' ? 2000 : 255)];
                        if ($type === 'email') {
                            $rules[$field][] = 'email:rfc';
                        } elseif ($type === 'date') {
                            $rules[$field][] = 'date_format:Y-m-d';
                        } elseif ($field === 'Sexo') {
                            $rules[$field][] = Rule::in(['Masculino', 'Feminino']);
                        } elseif ($type === 'tel') {
                            $rules[$field][] = 'regex:/^(?:\d{10,11}|\(\d{2}\) \d{4,5}-\d{4})$/D';
                        } elseif ($field === 'PIS_PASEP') {
                            $rules[$field][] = 'regex:/^(?:\d{11}|\d{3}\.\d{5}\.\d{2}-\d)$/D';
                        }
                    }
                    $details = $request->validateWithBag($bag, $rules);
                    foreach (SupplierFields::fields() as $field => [$label, $type]) {
                        if (isset($details[$field]) && ($type === 'tel' || $field === 'PIS_PASEP')) {
                            $details[$field] = preg_replace('/\D/', '', $details[$field]);
                        }
                    }
                    if (! empty($details['CPF_Socio'])) {
                        $cpf = Normalizer::document($details['CPF_Socio']);
                        if (! preg_match('/^[\d\s.\/-]+$/D', $details['CPF_Socio']) || strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                            throw ValidationException::withMessages(['CPF_Socio' => 'Informe um CPF válido.'])->errorBag($bag);
                        }
                        $details['CPF_Socio'] = $cpf;
                    }
                    $payload = array_fill_keys(array_keys(SupplierFields::fields()), '');
                    $payload = array_replace($payload, $details, [
                        'Nome_Fornecedor' => $data['name'], 'CPF_CNPJ' => $record['document_key'],
                        'PF_PJ' => strlen($record['document_key']) === 11 ? 'PF' : 'PJ',
                        'Data_Atualizacao' => now()->format('Y-m-d'),
                    ]);
                    $record['raw_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
                }
                $id = DB::table($table)->insertGetId($record);
            }
            ActivityEvent::create(['actor_id' => $request->user()->id, 'action' => 'catalog_created', 'metadata' => ['kind' => $data['kind'], 'id' => $id], 'created_at' => now()]);
        }, 3);

        $destination = $data['kind'] === 'supplier' && ($data['return_to'] ?? null) === 'suppliers.index'
            ? 'suppliers.index' : 'catalogs.index';

        return redirect()->route($destination)->with('status', 'Cadastro salvo.');
    }
}
