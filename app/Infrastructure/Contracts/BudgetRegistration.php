<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Security\CatalogAccess;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BudgetRegistration
{
    public function __construct(private TransactionManager $transactions, private ContractAccess $access) {}

    public function create(ActorIdentity $identity, int $contractId, array $input): array
    {
        return Cache::lock('contracts-budget-registration', 30)->block(5, fn () => $this->transactions->run(function () use ($identity, $contractId, $input) {
            $context = $this->access->lockForSave($identity, $contractId);
            $actor = User::findOrFail($identity->id);
            CatalogAccess::authorize($actor, 'budget');
            $contract = Contract::findOrFail($contractId);
            CatalogAccess::destination($actor, (int) $contract->fund_id, $contract->department_id);
            $data = Validator::make($input, ['contract_version' => 'required|integer|min:1',
                'ficha' => 'required|string|max:64', 'description' => 'required|string|max:5000',
                'allocation' => 'required|string|max:2000'])->validate();
            if ((int) $data['contract_version'] !== $context->contract->version()) {
                throw new ConflictHttpException('O contrato mudou. Recarregue a página.');
            }
            $ficha = trim($data['ficha']);
            if ($ficha === '' || DB::table('tb_Dotacao')->whereRaw('TRIM(Ficha) = ?', [$ficha])->exists()) {
                throw ValidationException::withMessages(['ficha' => 'Ficha já cadastrada ou inválida. Consulte o cadastro.']);
            }
            $fund = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $contract->fund_id)->lockForUpdate()->first();
            abort_unless($fund, 422, 'Fundo indisponível.');
            $department = $contract->department_id ? DB::table('contract_departments')->where('id', $contract->department_id)->lockForUpdate()->first() : null;
            abort_if($contract->department_id && ! $department, 422, 'Secretaria indisponível.');
            $record = ['Ficha' => $ficha, 'Descricao' => $data['description'], 'Dotacao' => $data['allocation'],
                'Fundo' => $fund->name, 'Secretaria' => $department?->name];
            $id = DB::table('tb_Dotacao')->insertGetId($record, 'ID_Dotacao');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'catalog_created',
                'metadata' => ['kind' => 'budget', 'id' => $id, 'contract_id' => $contractId, 'values' => $record], 'created_at' => now()]);

            return ['status' => 'found', 'description' => $record['Descricao'], 'allocation' => $record['Dotacao']];
        }));
    }
}
