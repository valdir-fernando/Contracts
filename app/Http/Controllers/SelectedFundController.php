<?php

namespace App\Http\Controllers;

use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SelectedFundController extends Controller
{
    public function update(Request $request, LockActor $actors, SelectedFund $funds)
    {
        $data = $request->validate(['fund_id' => 'required|integer|min:1|exists:contract_funds,id',
            'year' => $request->input('year') === 'all' ? 'nullable|in:all' : 'nullable|integer|min:1|max:'.today()->year]);
        $fundId = DB::transaction(function () use ($request, $actors, $funds, $data) {
            $actor = $actors->lock($request->user());
            $fund = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $data['fund_id'])->lockForUpdate()->first();
            abort_unless($fund && $funds->allows($actor, $fund->id), 403);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'fund_selected',
                'metadata' => ['before' => $request->session()->get('fund_id'), 'fund_id' => $fund->id], 'created_at' => now()]);

            return $fund->id;
        }, 3);
        // Session state changes only after authorization and audit have committed.
        $request->session()->put('fund_id', $fundId);

        return redirect()->route('dashboard', empty($data['year']) ? [] : ['year' => $data['year']]);
    }
}
