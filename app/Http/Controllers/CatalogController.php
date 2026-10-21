<?php

namespace App\Http\Controllers;

use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Funds\FundRegistration;
use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Suppliers\SupplierRegistrationInput;
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
    public function __construct(private LockActor $actors, private FundRegistration $fundRegistration) {}

    public function index(Request $request)
    {
        CatalogAccess::authorize($request->user(), 'department');
        if (! $request->user()->isAdmin()) {
            $municipalIds = CatalogAccess::funds($request->user())->get()->filter(fn ($fund) => Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA')->pluck('id');

            return response()->view('administration.departments', ['departments' => CatalogAccess::departments($request->user())->orderBy('name')->get(['id', 'name']),
                'canCreate' => $request->user()->scopes()->whereIn('fund_id', $municipalIds)->whereNull('department_id')->where(fn ($query) => $query->whereNull('department')->orWhere('department', ''))->exists()])
                ->header('Cache-Control', 'private, no-store');
        }

        return view('administration.catalogs', ['funds' => CatalogAccess::funds($request->user())->orderBy('name')->get(),
            'departments' => CatalogAccess::departments($request->user())->orderBy('name')->get(),
            'documentSettings' => app(DocumentSettings::class)->current(),
            'scopes' => AccessScope::orderBy('fund')->orderBy('department')->get()]);
    }

    public function show(Request $request, string $kind, int $catalog)
    {
        CatalogAccess::authorize($request->user(), $kind);
        abort_unless(in_array($kind, ['fund', 'department'], true), 404);
        $table = $kind === 'fund' ? 'contract_funds' : 'contract_departments';
        $columns = $kind === 'fund' ? ['id', 'name', 'document'] : ['id', 'name'];
        $record = ($kind === 'fund' ? CatalogAccess::funds($request->user()) : CatalogAccess::departments($request->user()))->select($columns)->where('id', $catalog)->first();
        abort_unless($record, 404);

        return view('administration.catalog-detail', ['record' => $record, 'kind' => $kind,
            'contracts' => Contract::visibleTo($request->user())
                ->where($kind === 'fund' ? 'fund_id' : 'department_id', $record->id)->latest('id')->paginate(20)]);
    }

    public function listing(Request $request, string $kind)
    {
        CatalogAccess::authorize($request->user(), $kind === 'manager' ? 'manager-fund' : $kind);
        if ($kind === 'budget') {
            return redirect()->route('catalogs.budgets.create');
        }

        [$table, $title, $columns] = match ($kind) {
            'inspector' => ['contract_inspectors', 'Fiscal', ['name' => 'Nome', 'fund_name_key' => 'Fundo', 'department_name_key' => 'Secretaria']],
            'manager' => ['tb_Gestor', 'Gestor', ['name' => 'Nome', 'fund_name_key' => 'Fundo']],
            'manager-fund' => ['tb_Gestor', 'Gestor_Fundo', ['fund_name_key' => 'Fundo', 'name' => 'Gestor']],
            'budget' => ['tb_Dotacao', 'Dotação', ['Ficha' => 'Ficha', 'Descricao' => 'Descrição', 'Dotacao' => 'Dotação', 'Fundo' => 'Fundo', 'Secretaria' => 'Secretaria']],
            default => abort(404),
        };

        return response()->view('administration.catalog-list', [
            'title' => $title, 'columns' => $columns,
            'records' => CatalogAccess::byOrganization($request->user(), DB::table($table), $kind === 'budget' ? 'Fundo' : 'fund_name_key', $kind === 'budget' ? 'Secretaria' : ($kind === 'inspector' ? 'department_name_key' : null), $kind !== 'budget')->when(in_array($table, ['tb_Gestor', 'tb_Dotacao']), fn ($query) => $query->whereNull('deleted_at'))->select(array_keys($columns))->orderBy(array_key_first($columns))->paginate(30),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $kind = $request->input('kind');
        if ($kind === 'scope') {
            abort_unless($request->user()->isAdmin(), 403);
        } elseif (! $request->user()->isAdmin()) {
            CatalogAccess::authorize($request->user(), is_string($kind) ? $kind : '');
        }
        if ($kind === 'fund') {
            try {
                $this->fundRegistration->save($request->user(), null, $request->all());
            } catch (ValidationException $error) {
                throw $error->errorBag('catalog-fund');
            }

            return redirect()->route($request->user()->isAdmin() ? 'catalogs.index' : 'funds.index')->with('status', 'Cadastro salvo.');
        }
        $bag = in_array($kind, ['fund', 'department', 'supplier']) ? 'catalog-'.$kind : 'default';
        $data = $request->validateWithBag($bag, ['kind' => ['required', Rule::in(['fund', 'department', 'supplier', 'scope'])],
            'return_to' => ['nullable', Rule::in(['catalogs.index', 'suppliers.index'])],
            'name' => 'required_unless:kind,scope|nullable|string|max:255', 'document' => 'required_if:kind,supplier|nullable|string|max:32',
            'Endereco' => 'required_if:kind,supplier|nullable|string|max:2000',
            'fund_id' => 'required_if:kind,scope|nullable|integer|exists:contract_funds,id',
            'department_id' => 'nullable|integer|exists:contract_departments,id']);
        if (in_array($data['kind'], ['fund', 'supplier']) && ! Normalizer::validDocument(Normalizer::document($data['document'] ?? ''))) {
            throw ValidationException::withMessages(['document' => 'Informe um CPF/CNPJ válido.'])->errorBag($bag);
        }
        DB::transaction(function () use ($data, $request, $bag) {
            $actor = $this->actors->lock($request->user());
            if ($data['kind'] === 'scope') {
                abort_unless($actor->isAdmin(), 403);
            } else {
                CatalogAccess::authorize($actor, $data['kind']);
            }
            if ($data['kind'] === 'department' && ! $actor->isAdmin()) {
                $municipality = CatalogAccess::funds($actor)->get()->filter(fn ($fund) => Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA');
                abort_unless($municipality->count() === 1 && $actor->scopes()->where('fund_id', $municipality->first()->id)->whereNull('department_id')->where(fn ($query) => $query->whereNull('department')->orWhere('department', ''))->exists(), 403);
            }
            if ($data['kind'] === 'scope') {
                $fund = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $data['fund_id'])->lockForUpdate()->first();
                abort_unless($fund, 422, 'Selecione um Fundo disponível.');
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
                    $data['name'] = mb_strtoupper($data['name'], 'UTF-8');
                    $record['name'] = $data['name'];
                    $details = (new SupplierRegistrationInput)->details($request->all(), $bag);
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

        $destination = $data['kind'] === 'supplier' && (($data['return_to'] ?? null) === 'suppliers.index' || ! $request->user()->isAdmin())
            ? 'suppliers.index' : 'catalogs.index';

        return redirect()->route($destination)->with('status', 'Cadastro salvo.');
    }
}
