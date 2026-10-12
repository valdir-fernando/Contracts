<?php

namespace Tests\Feature;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Contracts\Ports\ContractStore;
use App\Application\Contracts\SaveContract;
use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\InputRejected;
use App\Application\Exceptions\RecordNotFound;
use App\Application\Security\ActorIdentity;
use App\Domain\Contracts\ContractState;
use App\Infrastructure\Contracts\ContractMapper;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\ContractWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContractArchitectureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_eloquent_mapping_preserves_raw_json_money_dates_and_omitted_fields(): void
    {
        $model = Contract::create(['numero' => '001/26', 'objeto' => 'Original', 'valor_total' => '1908.6300',
            'vigencia_inicio' => '2026-10-01', 'quality_issues' => [['code' => 'historical', 'message' => 'Preservar']], 'ownership_resolved' => false]);
        $model->refresh();
        $state = ContractMapper::toDomain($model);
        $this->assertSame('1908.6300', $state->get('valor_total'));
        $unchanged = ContractMapper::toModel($state);
        $this->assertSame([], $unchanged->getDirty());
        $this->assertSame($model->getAttributes(), $unchanged->getRawOriginal());
        $this->assertSame($model->quality_issues, $unchanged->quality_issues);
        $this->assertSame($model->valor_total, $unchanged->valor_total);
        $this->assertSame('2026-10-01', $unchanged->vigencia_inicio->toDateString());
        $changed = ContractMapper::toModel($state->with(['objeto' => 'Corrigido']));
        $this->assertSame(['objeto' => 'Corrigido'], $changed->getDirty());
        $this->assertSame($model->quality_issues, $changed->quality_issues);
    }

    public function test_store_rejects_a_snapshot_from_a_different_authorized_contract(): void
    {
        $context = new AuthorizedContract(new ActorIdentity(1, 0), true, new ContractState(1, ['version' => 1]));
        try {
            app(ContractStore::class)->save($context, new ContractState(2, ['version' => 1]), 'Correção', false);
            $this->fail('O adapter não deve gravar um contrato diferente do contexto autorizado.');
        } catch (LogicException $error) {
            $this->assertSame('O contrato gravado deve corresponder ao contrato autorizado.', $error->getMessage());
            $this->assertDatabaseCount('contracts', 0);
            $this->assertDatabaseCount('contract_audits', 0);
        }
    }

    public static function mutationOperations(): array
    {
        return [['access'], ['actor'], ['save'], ['duplicate'], ['lifecycle'], ['destination']];
    }

    #[DataProvider('mutationOperations')]
    public function test_mutation_adapters_refuse_to_run_without_a_transaction(string $operation): void
    {
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        $identity = new ActorIdentity(1, 0);
        $context = new AuthorizedContract($identity, true, new ContractState(1, ['version' => 1]));
        $store = app(ContractStore::class);
        $this->expectException(LogicException::class);
        match ($operation) {
            'access' => app(ContractAccess::class)->lockForSave($identity, 1),
            'destination' => app(ContractAccess::class)->canAssign($context, 1, null),
            'actor' => app(LockActor::class)->lock(new User),
            'save' => $store->save($context, $context->contract, 'Correção', false),
            'duplicate' => $store->hasDuplicate($context, $context->contract),
            'lifecycle' => $store->changeLifecycle($context, false, 'Exclusão'),
        };
    }

    public static function applicationFailures(): array
    {
        return [['validation', 422], ['access', 403], ['conflict', 409], ['missing', 404]];
    }

    #[DataProvider('applicationFailures')]
    public function test_application_failures_are_mapped_at_the_http_boundary(string $failure, int $status): void
    {
        Route::post('/architecture-test/failures/{failure}', function (string $failure) {
            throw match ($failure) {
                'validation' => new InputRejected(['objeto' => 'Corrija o objeto.']),
                'access' => new AccessDenied,
                'conflict' => new ConcurrencyConflict('Recarregue os dados.'),
                'missing' => new RecordNotFound,
            };
        })->middleware('web');
        $response = $this->postJson('/architecture-test/failures/'.$failure)->assertStatus($status);
        if ($failure === 'validation') {
            $response->assertJsonValidationErrors('objeto');
        }
    }

    public function test_application_validation_and_access_failures_are_framework_free_and_leave_no_writes(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        try {
            app(SaveContract::class)->execute(new ActorIdentity($admin->id, $admin->session_version), []);
            $this->fail('Dados inválidos devem falhar no contrato de aplicação.');
        } catch (InputRejected $error) {
            $this->assertArrayHasKey('fund_id', $error->errors);
        }
        User::whereKey($admin->id)->increment('session_version');
        try {
            app(SaveContract::class)->execute(new ActorIdentity($admin->id, $admin->session_version), []);
            $this->fail('Uma identidade revogada deve ser recusada.');
        } catch (AccessDenied) {
            $this->assertDatabaseCount('contracts', 0);
            $this->assertDatabaseCount('contract_audits', 0);
        }
    }

    public function test_writer_returns_saved_authorized_snapshot_without_reopening_the_record_after_commit(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $model = Contract::create(['numero' => '001/26', 'objeto' => 'Original', 'quality_issues' => [['code' => 'historical']], 'version' => 1]);
        $level = DB::transactionLevel();
        $selectLevels = [];
        DB::listen(function ($query) use (&$selectLevels) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'contracts')) {
                $selectLevels[] = DB::transactionLevel();
            }
        });
        $saved = app(ContractWriter::class)->save($admin, ['version' => 1, 'objeto' => 'Corrigido', 'reason' => 'Correção documentada'], $model);
        $this->assertNotEmpty($selectLevels);
        foreach ($selectLevels as $selectLevel) {
            $this->assertGreaterThan($level, $selectLevel, 'O registro só pode ser lido dentro da transação autorizada.');
        }
        $this->assertFalse($saved->wasRecentlyCreated);
        $this->assertSame([['code' => 'historical']], $saved->quality_issues);
        $this->assertSame('Corrigido', $saved->objeto);
        $this->assertSame(2, $saved->version);
        $this->assertArrayNotHasKey('quality_issues', ContractAudit::latest('id')->first()->changes);
    }
}
