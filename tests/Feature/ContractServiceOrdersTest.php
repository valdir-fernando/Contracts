<?php

namespace Tests\Feature;

use App\Application\Exceptions\AccessDenied;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Contracts\RegisterServiceOrder;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ContractServiceOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => [],
            'valor_total' => '5000.0000', 'vigencia_fim_original' => '2026-12-31', 'prazo_obra' => 'Prazo histórico', 'n_os' => 'LEGADO', 'data_os' => '2026-01-01']);
        $this->actingAs($this->admin);
    }

    private function url(): string
    {
        return '/contracts/'.$this->contract->id.'/service-orders';
    }

    private function data(array $extra = []): array
    {
        return array_replace(['number' => 'OS-01', 'issued_on' => '2026-10-15', 'deadline' => '90 dias',
            'contract_version' => $this->contract->fresh()->version, 'notes' => 'Ordem documental'], $extra);
    }

    private function amendment(?int $contractId = null): ContractAmendment
    {
        return ContractAmendment::create(['contract_id' => $contractId ?? $this->contract->id, 'number' => 'ADT-01',
            'event_date' => '2026-10-15', 'payload' => [], 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);
    }

    public function test_multiple_orders_and_amendment_orders_are_audited_without_changing_contract_terms(): void
    {
        $initial = $this->contract->fresh()->version;
        $this->postJson($this->url(), $this->data(['created_by' => 999, 'contract_id' => 999]))->assertCreated()->assertJsonPath('contract_version', $initial + 1);
        $amendment = $this->amendment();
        $this->postJson($this->url(), $this->data(['number' => 'OS-02', 'amendment_id' => $amendment->id]))->assertCreated();
        $this->postJson($this->url(), $this->data(['number' => 'OS-03', 'amendment_id' => $amendment->id]))->assertCreated();
        $this->assertDatabaseCount('contract_service_orders', 3);
        $this->assertDatabaseCount('contract_audits', 3);
        $this->assertDatabaseHas('contract_service_orders', ['contract_id' => $this->contract->id, 'created_by' => $this->admin->id, 'number' => 'OS-01', 'amendment_id' => null]);
        $fresh = $this->contract->fresh();
        $this->assertSame($initial + 3, $fresh->version);
        $this->assertSame('5000.0000', $fresh->valor_total);
        $this->assertSame('2026-12-31', $fresh->vigencia_fim_original->format('Y-m-d'));
        $this->assertSame('Prazo histórico', $fresh->prazo_obra);
        $this->assertSame('LEGADO', $fresh->n_os);
        $this->assertSame('2026-01-01', $fresh->data_os->format('Y-m-d'));
        $audit = ContractAudit::first();
        $this->assertSame('service_order_created', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('OS-01', $audit->changes['ordem_servico.number']['after']);
        $this->getJson($this->url())->assertOk()->assertJsonCount(3, 'orders')->assertJsonCount(1, 'amendments')
            ->assertJsonPath('orders.0.amendment', 'ADT-01')->assertJsonMissingPath('orders.0.created_by')->assertJsonMissingPath('orders.0.contract_id');
    }

    public function test_validation_stale_version_and_foreign_or_deleted_amendments_cannot_write(): void
    {
        $this->postJson($this->url(), $this->data(['issued_on' => '2026-02-30', 'number' => ' ', 'deadline' => '']))->assertUnprocessable();
        $this->postJson($this->url(), $this->data(['contract_version' => 999]))->assertConflict()->assertJsonMissingPath('contract');
        $other = $this->contract->replicate();
        $other->save();
        $foreign = $this->amendment($other->id);
        $this->postJson($this->url(), $this->data(['amendment_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('amendment_id');
        $deleted = $this->amendment();
        $deleted->delete();
        $this->postJson($this->url(), $this->data(['amendment_id' => $deleted->id]))->assertUnprocessable();
        $this->assertDatabaseCount('contract_service_orders', 0);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_scopes_and_current_actor_are_checked_for_reads_and_writes(): void
    {
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $this->actingAs($user);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), $this->data())->assertForbidden();
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'os-scope'), 'fund' => 'Fundo', 'fund_id' => $this->contract->fund_id]);
        $user->scopes()->attach($scope);
        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url(), $this->data())->assertCreated();
        $identity = new ActorIdentity($user->id, $user->session_version);
        $user->increment('session_version');
        try {
            app(RegisterServiceOrder::class)->save($identity, $this->contract->id, $this->data());
            $this->fail('Stale actor accepted');
        } catch (AccessDenied) {
            $this->assertDatabaseCount('contract_service_orders', 1);
        }
    }

    public function test_audit_failure_rolls_back_order_and_contract_version(): void
    {
        $initial = $this->contract->fresh()->version;
        ContractAudit::creating(fn () => throw new RuntimeException('audit unavailable'));
        try {
            app(RegisterServiceOrder::class)->save(new ActorIdentity($this->admin->id, $this->admin->session_version), $this->contract->id, $this->data());
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
            $this->assertDatabaseCount('contract_service_orders', 0);
            $this->assertSame($initial, $this->contract->fresh()->version);
        } finally {
            ContractAudit::flushEventListeners();
        }
    }

    public function test_history_is_paginated_and_keeps_link_to_a_soft_deleted_amendment(): void
    {
        $amendment = $this->amendment();
        for ($i = 1; $i <= 11; $i++) {
            $this->postJson($this->url(), $this->data(['number' => 'OS-'.$i, 'amendment_id' => $amendment->id]))->assertCreated();
        }
        $amendment->delete();
        $this->getJson($this->url())->assertOk()->assertJsonCount(10, 'orders')->assertJsonCount(0, 'amendments')
            ->assertJsonPath('last_page', 2)->assertJsonPath('orders.0.amendment', 'ADT-01');
        $this->getJson($this->url().'?page=2')->assertOk()->assertJsonCount(1, 'orders');
    }

    public function test_icon_and_dialog_exist_on_contract_and_saved_amendment_forms(): void
    {
        $this->get('/contracts/create')->assertOk()->assertSee('Salve o contrato antes de cadastrar ordens de serviço');
        $this->get('/contracts/'.$this->contract->id.'/edit')->assertOk()->assertSee('service-order-input-control')
            ->assertSee('data-service-orders-dialog', false)->assertSee('Ordens de Serviço');
        $amendment = $this->amendment();
        $this->get('/contracts/'.$this->contract->id.'/amendments/'.$amendment->id.'/edit')->assertOk()
            ->assertSee('data-service-orders-open', false)->assertSee('data-amendment="'.$amendment->id.'"', false);
    }
}
