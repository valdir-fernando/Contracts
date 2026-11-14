<?php

namespace App\Http\Controllers;

use App\Infrastructure\Atas\AtaContractor;
use App\Infrastructure\Atas\AtaEditorQuery;
use App\Infrastructure\Atas\AtaParticipants;
use App\Infrastructure\Atas\AtaProductLookup;
use App\Infrastructure\Atas\AtaRegistration;
use App\Infrastructure\Atas\RemoveSavedAtaParticipant;
use App\Infrastructure\Atas\SaveAtaProduct;
use App\Infrastructure\Atas\SavedAtaParticipants;
use App\Infrastructure\Security\LockActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AtaController extends Controller
{
    public function saveProduct(Request $request, int $ata, SaveAtaProduct $products)
    {
        return response()->json($products->save($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 201,
            ['Cache-Control' => 'private, no-store']);
    }

    public function productList(Request $request, int $ata, AtaProductLookup $products)
    {
        return response()->json($products->listing($request->user(), $ata, $request->query()), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function productLookup(Request $request, int $ata, AtaProductLookup $products)
    {
        return response()->json($products->find($request->user(), $ata, $request->query()), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function removeSavedParticipant(Request $request, int $ata, int $participant, RemoveSavedAtaParticipant $participants)
    {
        $state = $participants->remove($request->user(), $ata, $participant, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function addSavedParticipant(Request $request, int $ata, SavedAtaParticipants $participants)
    {
        $state = $participants->add($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 201, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, AtaRegistration $registration)
    {
        $result = $registration->save($request->user(), $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($result, 201, ['Cache-Control' => 'private, no-store']);
    }

    public function lookup(Request $request, AtaEditorQuery $query)
    {
        $data = $request->validate(['number' => 'required|string|max:32']);

        return response()->json($query->find($request->user(), $data['number']), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function contractorDefaults(Request $request, int $fund, AtaContractor $contractor)
    {
        return response()->json(['values' => $contractor->defaults($request->user(), $fund)], 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request, LockActor $actors, AtaParticipants $participants)
    {
        $actors->current($request->user());
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->whereRaw("TRIM(name) <> ''")
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);

        $data = $request->validate(['draft' => 'sometimes|required|string|max:2048']);
        $draftToken = $data['draft'] ?? $participants->token($request->user());

        return response()->view('atas.create', ['funds' => $funds, 'draftToken' => $draftToken,
            'draftState' => $participants->state($request->user(), $draftToken)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function removeParticipant(Request $request, AtaParticipants $participants, int $participant)
    {
        $data = $request->validate(['draft_token' => 'required|string|max:2048']);
        $state = $participants->remove($request->user(), $data['draft_token'], $participant, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function addParticipant(Request $request, AtaParticipants $participants)
    {
        $data = $request->validate(['draft_token' => 'required|string|max:2048']);
        $state = $participants->add($request->user(), $data['draft_token'], $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 201, ['Cache-Control' => 'private, no-store']);
    }
}
