<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\ContractTypeRule;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractTypeRuleController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('administration.contract-types', ['rules' => ContractTypeRule::orderBy('name')->paginate(20)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => 'required|string|max:150', 'reason' => 'required|string|min:10|max:1000',
            'required_fields' => 'nullable|array|max:7', 'required_fields.*' => ['string', 'distinct', Rule::in(array_keys(ContractTypeRule::FIELDS))]]);
        DB::transaction(function () use ($request, $data) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isAdmin() && (int) $request->session()->get('auth_version', 0) === $actor->session_version, 403);
            $rule = ContractTypeRule::firstOrNew(['name_key' => Normalizer::name($data['name'])]);
            $before = $rule->required_fields;
            $rule->name = $data['name'];
            $rule->required_fields = $data['required_fields'] ?? [];
            $rule->save();
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'contract_type_rule_updated',
                'metadata' => ['rule_id' => $rule->id, 'before' => $before, 'after' => $rule->required_fields, 'reason' => $data['reason']], 'created_at' => now()]);
        });

        return redirect()->route('contract-types.index')->with('status', 'Requisitos do tipo atualizados.');
    }
}
