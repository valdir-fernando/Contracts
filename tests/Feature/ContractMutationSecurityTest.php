<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\ContractLifecycle;
use App\Services\ContractWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ContractMutationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contract $contract;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin fictício', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '001/26', 'processo' => 'PROCESSO', 'fund_id' => $this->fund, 'ownership_resolved' => true, 'quality_issues' => []]);
    }

    public static function mutations(): array
    {
        return [['create'], ['update'], ['delete'], ['restore']];
    }

    #[DataProvider('mutations')]
    public function test_revoked_actor_snapshot_cannot_mutate_contracts(string $operation): void
    {
        if ($operation === 'restore') {
            $this->contract->delete();
        }
        User::whereKey($this->admin->id)->increment('session_version');
        $before = $this->contract->fresh()->getAttributes();
        try {
            if ($operation === 'create') {
                app(ContractWriter::class)->save($this->admin, ['numero' => '002/26', 'processo' => 'NOVO', 'fornecedor_nome_snapshot' => 'Fornecedor', 'fornecedor_documento_snapshot' => '11222333000181', 'objeto' => 'Objeto', 'data_contrato' => '2026-10-01', 'vigencia_inicio' => '2026-10-01', 'vigencia_fim_original' => '2027-10-01', 'tipo' => 'Serviço', 'valor_total' => '1908,6300', 'fund_id' => $this->fund]);
            } elseif ($operation === 'update') {
                app(ContractWriter::class)->save($this->admin, ['version' => 1, 'reason' => 'Edição documentada', 'processo' => 'ALTERADO'], $this->contract);
            } else {
                app(ContractLifecycle::class)->change($this->admin, $this->contract, ['version' => 1, 'reason' => 'Ação documentada', 'confirmation' => '001/26'], $operation === 'restore');
            }
            $this->fail('Uma sessão revogada não deve permitir mutação.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->contract->fresh()->getAttributes());
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_conflict_response_rechecks_scope_before_showing_current_contract(): void
    {
        $regular = User::create(['name' => 'Usuário', 'username' => 'REGULAR', 'password' => 'SenhaFicticia123']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope'), 'fund' => 'Fundo A', 'fund_id' => $this->fund]);
        $regular->scopes()->attach($scope);
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo B', 'name_key' => 'FUNDO B', 'raw_payload' => '{}']);
        $this->mock(ContractWriter::class)->shouldReceive('save')->once()->andReturnUsing(function () use ($otherFund) {
            $this->contract->update(['fund_id' => $otherFund, 'objeto' => 'DADO RESTRITO APÓS CONFLITO', 'version' => 2]);
            throw new ConflictHttpException('Conflito simulado');
        });
        $this->actingAs($regular)->put('/contracts/'.$this->contract->id, ['version' => 1, 'reason' => 'Edição documentada'])->assertForbidden()->assertDontSee('DADO RESTRITO APÓS CONFLITO');
    }

    public function test_conflict_response_rejects_a_session_revoked_during_the_write(): void
    {
        $this->mock(ContractWriter::class)->shouldReceive('save')->once()->andReturnUsing(function () {
            User::whereKey($this->admin->id)->increment('session_version');
            throw new ConflictHttpException('Conflito simulado');
        });
        $this->actingAs($this->admin)->put('/contracts/'.$this->contract->id, ['version' => 1, 'reason' => 'Edição documentada'])->assertForbidden();
    }

    public function test_conflict_response_returns_not_found_if_the_contract_was_deleted(): void
    {
        $this->mock(ContractWriter::class)->shouldReceive('save')->once()->andReturnUsing(function () {
            $this->contract->delete();
            throw new ConflictHttpException('Conflito simulado');
        });
        $this->actingAs($this->admin)->put('/contracts/'.$this->contract->id, ['version' => 1, 'reason' => 'Edição documentada'])->assertNotFound();
    }

    public function test_restoration_and_audit_roll_back_together(): void
    {
        $this->contract->delete();
        $before = $this->contract->fresh()->getAttributes();
        ContractAudit::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            app(ContractLifecycle::class)->change($this->admin, $this->contract, ['version' => 1, 'reason' => 'Restaurar registro', 'confirmation' => '001/26'], true);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertSame($before, $this->contract->fresh()->getAttributes());
        $this->assertDatabaseCount('contract_audits', 0);
    }
}
