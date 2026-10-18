<?php

namespace App\Http\Controllers;

use App\Infrastructure\Funds\FundRegistration;
use App\Infrastructure\Security\LockActor;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FundController extends Controller
{
    public function lookup(Request $request, LockActor $actors)
    {
        abort_unless($actors->current($request->user())->isAdmin(), 403);
        $data = $request->validate(['cnpj' => ['bail', 'required', 'string', 'max:32', function ($attribute, $value, $fail) {
            $document = Normalizer::document($value);
            if (! preg_match('/^[\d\s.\/\-]+$/D', $value) || strlen($document) !== 14 || ! Normalizer::validDocument($document)) {
                $fail('Informe um CNPJ válido.');
            }
        }]]);
        $document = Normalizer::document($data['cnpj']);
        $matches = DB::table('contract_funds')->whereNull('deleted_at')->where(function ($query) use ($document) {
            $query->where('document_key', $document)
                ->orWhereRaw("replace(replace(replace(replace(document, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$document]);
        })->orderBy('id')->limit(2)->pluck('id');
        abort_if($matches->count() > 1, 409, 'Há mais de um Fundo com este CNPJ. Selecione o cadastro na lista.');

        return response()->json(['found' => $matches->isNotEmpty(), 'edit_url' => $matches->isNotEmpty()
            ? route('funds.index', ['edit' => $matches->first()]) : null], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request, LockActor $actors, FundRegistration $registration)
    {
        abort_unless($actors->current($request->user())->isAdmin(), 403);
        $id = $request->validate(['edit' => 'nullable|integer|min:1'])['edit'] ?? null;
        $record = $id ? DB::table('contract_funds')->whereNull('deleted_at')->find($id) : null;
        abort_if($id && ! $record, 404);
        $raw = $record ? json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR) : [];
        $values = ['name' => $record?->name, 'document' => $record?->document] + array_intersect_key($raw, FundRegistration::DETAILS);
        $records = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('name')->paginate(20);
        $records->through(function ($item) {
            $raw = json_decode($item->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $document = preg_replace('/\D/', '', $item->document ?? '');
            $document = strlen($document) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $document) : $item->document;

            return (object) (['id' => $item->id, 'name' => $item->name, 'document' => $document] + array_intersect_key($raw, FundRegistration::DETAILS));
        });

        return response()->view('administration.funds', ['record' => $record, 'values' => $values, 'records' => $records,
            'details' => FundRegistration::DETAILS, 'revision' => $record ? $registration->revision($record) : null,
            'canDelete' => $record && ! $registration->linked($record)], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, FundRegistration $registration)
    {
        $id = $registration->save($request->user(), null, $request->all());

        return redirect()->route('funds.index', ['edit' => $id])->with('status', 'Fundo cadastrado.');
    }

    public function update(Request $request, int $fund, FundRegistration $registration)
    {
        $registration->save($request->user(), $fund, $request->all());

        return redirect()->route('funds.index', ['edit' => $fund])->with('status', 'Fundo atualizado.');
    }

    public function destroy(Request $request, int $fund, FundRegistration $registration)
    {
        $registration->delete($request->user(), $fund, $request->all());

        return redirect()->route('funds.index')->with('status', 'Fundo excluído.');
    }
}
