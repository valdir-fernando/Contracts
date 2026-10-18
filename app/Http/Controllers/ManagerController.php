<?php

namespace App\Http\Controllers;

use App\Infrastructure\Managers\ManagerRegistration;
use App\Infrastructure\Managers\ManagerSignatory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagerController extends Controller
{
    public function index(Request $request, ManagerRegistration $registration)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $id = $request->validate(['edit' => 'nullable|integer|min:1'])['edit'] ?? null;
        $record = $id ? DB::table('tb_Gestor')->whereNull('deleted_at')->find($id) : null;
        abort_if($id && ! $record, 404);
        $values = [];
        $legacyDates = [];
        if ($record) {
            $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $values = array_intersect_key($raw, ManagerRegistration::FIELDS);
            $values['Nome_Gestor'] = $record->name;
            foreach (['Data_Nasc', 'Data_Decreto'] as $key) {
                $values[$key] = ManagerRegistration::dateForForm($values[$key] ?? null);
                if (! $values[$key] && filled($raw[$key] ?? null)) {
                    $legacyDates[$key] = $raw[$key];
                }
            }
        }
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'name_key']);
        $values['fund_id'] = $record ? ($funds->where('name_key', $record->fund_name_key)->count() === 1 ? $funds->firstWhere('name_key', $record->fund_name_key)?->id : null) : null;
        $hasActiveSignature = DB::table('tb_Gestor')->whereNull('deleted_at')
            ->where('fund_name_key', ManagerSignatory::MUNICIPAL_FUND)->get(['raw_payload'])
            ->contains(fn ($manager) => (string) (json_decode($manager->raw_payload, true)['Assinatura'] ?? 0) === '1');
        $signatureAvailable = ! $hasActiveSignature || ($record && $record->fund_name_key === ManagerSignatory::MUNICIPAL_FUND
            && (string) ($values['Assinatura'] ?? 0) === '1');

        return response()->view('administration.managers', [
            'record' => $record, 'values' => $values, 'legacyDates' => $legacyDates, 'fields' => ManagerRegistration::FIELDS,
            'revision' => $record ? $registration->revision($record) : null,
            'funds' => $funds,
            'signatureAvailable' => $signatureAvailable,
            'municipalFundIds' => $funds->filter(fn ($fund) => ManagerSignatory::isMunicipal($fund->name))->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'records' => DB::table('tb_Gestor')->whereNull('deleted_at')->select(['id', 'name', 'fund_name_key'])->orderBy('name')->paginate(20),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $manager, ManagerRegistration $registration)
    {
        $registration->delete($request->user(), $manager, $request->all());

        return redirect()->route('catalogs.list', ['kind' => 'manager-fund'])->with('status', 'Gestor excluído.');
    }

    public function store(Request $request, ManagerRegistration $registration)
    {
        $id = $registration->save($request->user(), null, $request->all());

        return redirect()->route('catalogs.list', ['kind' => 'manager-fund', 'edit' => $id])->with('status', 'Gestor cadastrado.');
    }

    public function update(Request $request, int $manager, ManagerRegistration $registration)
    {
        $registration->save($request->user(), $manager, $request->all());

        return redirect()->route('catalogs.list', ['kind' => 'manager-fund', 'edit' => $manager])->with('status', 'Gestor atualizado.');
    }
}
