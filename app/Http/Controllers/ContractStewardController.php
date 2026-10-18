<?php

namespace App\Http\Controllers;

use App\Infrastructure\ContractStewards\ContractStewardRegistration;
use App\Infrastructure\Security\LockActor;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractStewardController extends Controller
{
    public function lookup(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        abort_unless($actor->isAdmin(), 403);
        $data = $request->validate(['cpf' => ['bail', 'required', 'string', 'max:18', function ($attribute, $value, $fail) {
            $cpf = Normalizer::document($value);
            if (! preg_match('/^[\d\s.\-]+$/D', $value) || strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                $fail('Informe um CPF válido.');
            }
        }]]);
        $column = DB::connection()->getQueryGrammar()->wrap('raw_payload->CPF_Gestor');
        $matches = DB::table('contract_stewards')->whereNull('deleted_at')
            ->whereRaw("replace(replace(replace({$column}, '.', ''), '-', ''), ' ', '') = ?", [Normalizer::document($data['cpf'])])
            ->orderBy('id')->limit(2)->pluck('id');
        abort_if($matches->count() > 1, 409, 'Há mais de um gestor com este CPF. Selecione o cadastro na lista.');

        return response()->json(['found' => $matches->isNotEmpty(), 'edit_url' => $matches->isNotEmpty()
            ? route('catalogs.list', ['kind' => 'contract-steward', 'edit' => $matches->first()]) : null],
            headers: ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request, ContractStewardRegistration $registration)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $id = $request->validate(['edit' => 'nullable|integer|min:1'])['edit'] ?? null;
        $record = $id ? DB::table('contract_stewards')->whereNull('deleted_at')->find($id) : null;
        abort_if($id && ! $record, 404);
        $values = [];
        $legacyDates = [];
        if ($record) {
            $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $values = array_intersect_key($raw, ContractStewardRegistration::FIELDS);
            $values['Nome_Gestor'] = $record->name;
            foreach (['Data_Nasc', 'Data_Decreto'] as $key) {
                $values[$key] = ContractStewardRegistration::dateForForm($values[$key] ?? null);
                if (! $values[$key] && filled($raw[$key] ?? null)) {
                    $legacyDates[$key] = $raw[$key];
                }
            }
        }
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'name_key']);
        $departments = DB::table('contract_departments')->orderBy('name')->get(['id', 'name', 'name_key']);
        $values['fund_id'] = $record ? ($funds->where('name_key', $record->fund_name_key)->count() === 1 ? $funds->firstWhere('name_key', $record->fund_name_key)?->id : null) : null;
        $values['department_id'] = $record ? ($departments->where('name_key', $record->department_name_key)->count() === 1 ? $departments->firstWhere('name_key', $record->department_name_key)?->id : null) : null;

        return response()->view('administration.contract-stewards', [
            'record' => $record, 'values' => $values, 'legacyDates' => $legacyDates, 'fields' => ContractStewardRegistration::FIELDS,
            'revision' => $record ? $registration->revision($record) : null,
            'funds' => $funds, 'departments' => $departments,
            'municipalFundIds' => $funds->filter(fn ($fund) => Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA')->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'records' => DB::table('contract_stewards')->whereNull('deleted_at')->select(['id', 'name', 'fund_name_key', 'department_name_key'])->orderBy('name')->paginate(20),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $steward, ContractStewardRegistration $registration)
    {
        $registration->delete($request->user(), $steward, $request->all());

        return redirect()->route('catalogs.list', ['kind' => 'contract-steward'])->with('status', 'Gestor excluído.');
    }

    public function store(Request $request, ContractStewardRegistration $registration)
    {
        $id = $registration->save($request->user(), null, $request->all());

        return redirect()->route('catalogs.list', ['kind' => 'contract-steward', 'edit' => $id])->with('status', 'Gestor cadastrado.');
    }

    public function update(Request $request, int $steward, ContractStewardRegistration $registration)
    {
        $registration->save($request->user(), $steward, $request->all());

        return redirect()->route('catalogs.list', ['kind' => 'contract-steward', 'edit' => $steward])->with('status', 'Gestor atualizado.');
    }
}
